<?php

namespace Bahaso\Passport\Guards;

use Bahaso\Passport\Exceptions\HttpRequestException;
use Bahaso\Passport\Exceptions\TokenExpiredException;
use Bahaso\Passport\Exceptions\TokenRevokedException;
use Bahaso\Passport\Exceptions\UserNotFoundException;
use Exception;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Bahaso\Passport\Passport;
use Illuminate\Container\Container;
use Bahaso\Passport\TransientToken;
use Bahaso\Passport\TokenRepository;
use Bahaso\Passport\ClientRepository;
use League\OAuth2\Server\ResourceServer;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;

class TokenGuard
{
    /**
     * The resource server instance.
     *
     * @var \League\OAuth2\Server\ResourceServer
     */
    protected $server;

    /**
     * The user provider implementation.
     *
     * @var \Illuminate\Contracts\Auth\UserProvider
     */
    protected $provider;

    /**
     * The token repository instance.
     *
     * @var \Bahaso\Passport\TokenRepository
     */
    protected $tokens;

    /**
     * The client repository instance.
     *
     * @var \Bahaso\Passport\ClientRepository
     */
    protected $clients;

    /**
     * The encrypter implementation.
     *
     * @var \Illuminate\Contracts\Encryption\Encrypter
     */
    protected $encrypter;

    /**
     * Create a new token guard instance.
     *
     * @param  \League\OAuth2\Server\ResourceServer $server
     * @param  \Illuminate\Contracts\Auth\UserProvider $provider
     * @param  \Bahaso\Passport\TokenRepository $tokens
     * @param  \Bahaso\Passport\ClientRepository $clients
     * @param  \Illuminate\Contracts\Encryption\Encrypter $encrypter
     */
    public function __construct(ResourceServer $server,
                                UserProvider $provider,
                                TokenRepository $tokens,
                                ClientRepository $clients,
                                Encrypter $encrypter)
    {
        $this->server = $server;
        $this->tokens = $tokens;
        $this->clients = $clients;
        $this->provider = $provider;
        $this->encrypter = $encrypter;
    }

    /**
     * Get the user for the incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @return mixed
     * @throws HttpRequestException
     */
    public function user(Request $request)
    {
        if ($request->bearerToken()) {
            return $this->authenticateViaBearerToken($request);
        } elseif ($request->cookie(Passport::cookie())) {
            return $this->authenticateViaCookie($request);
        } else {
            throw new HttpRequestException();
        }
    }

    /**
     * Get the client for the incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @return mixed
     * @throws HttpRequestException
     */
    public function client(Request $request)
    {
        if ($request->bearerToken()) {
            if (! $psr = $this->getPsrRequestViaBearerToken($request)) {
                throw new HttpRequestException();
            }

            return $this->clients->findActive(
                $psr->getAttribute('oauth_client_id')
            );
        } elseif ($request->cookie(Passport::cookie())) {
            if ($token = $this->getTokenViaCookie($request)) {
                return $this->clients->findActive($token['aud']);
            }
        }
    }

    /**
     * Authenticate the incoming request via the Bearer token.
     *
     * @param  \Illuminate\Http\Request $request
     * @return mixed
     * @throws HttpRequestException
     */
    protected function authenticateViaBearerToken($request)
    {
        if (! $psr = $this->getPsrRequestViaBearerToken($request)) {
            throw new HttpRequestException();
        }

        // If the access token is valid we will retrieve the user according to the user ID
        // associated with the token. We will use the provider implementation which may
        // be used to retrieve users from Eloquent. Next, we'll be ready to continue.
        $user = $this->provider->retrieveById(
            $psr->getAttribute('oauth_user_id') ?: null
        );

        if (! $user) {
            throw new UserNotFoundException();
        }

        // Next, we will assign a token instance to this user which the developers may use
        // to determine if the token has a given scope, etc. This will be useful during
        // authorization such as within the developer's Laravel model policy classes.
        $token = $this->tokens->find(
            $psr->getAttribute('oauth_access_token_id')
        );

        $clientId = $psr->getAttribute('oauth_client_id');

        // Finally, we will verify if the client that issued this token is still valid and
        // its tokens may still be used. If not, we will bail out since we don't want a
        // user to be able to send access tokens for deleted or revoked applications.
        if ($this->clients->revoked($clientId)) {
            throw new TokenRevokedException();
        }

        return $token ? $user->withAccessToken($token) : null;
    }

    /**
     * Authenticate and get the incoming PSR-7 request via the Bearer token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Psr\Http\Message\ServerRequestInterface
     */
    protected function getPsrRequestViaBearerToken($request)
    {
        // First, we will convert the Symfony request to a PSR-7 implementation which will
        // be compatible with the base OAuth2 library. The Symfony bridge can perform a
        // conversion for us to a Zend Diactoros implementation of the PSR-7 request.
        $psr = (new DiactorosFactory)->createRequest($request);

        try {
            return $this->server->validateAuthenticatedRequest($psr);
        } catch (OAuthServerException $e) {
            $request->headers->set('Authorization', '', true);

            throw new HttpRequestException(422, "Invalid token");
            /*Container::getInstance()->make(
                ExceptionHandler::class
            )->report($e);*/
        }
    }

    /**
     * Authenticate the incoming request via the token cookie.
     *
     * @param  \Illuminate\Http\Request $request
     * @return mixed
     * @throws HttpRequestException
     */
    protected function authenticateViaCookie($request)
    {
        if (! $token = $this->getTokenViaCookie($request)) {
            throw new HttpRequestException();
        }

        // If this user exists, we will return this user and attach a "transient" token to
        // the user model. The transient token assumes it has all scopes since the user
        // is physically logged into the application via the application's interface.
        if ($user = $this->provider->retrieveById($token['sub'])) {
            return $user->withAccessToken(new TransientToken);
        }
    }

    /**
     * Get the token cookie via the incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @return mixed
     * @throws HttpRequestException
     * @throws TokenExpiredException
     */
    protected function getTokenViaCookie($request)
    {
        // If we need to retrieve the token from the cookie, it'll be encrypted so we must
        // first decrypt the cookie and then attempt to find the token value within the
        // database. If we can't decrypt the value we'll bail out with a null return.
        try {
            $token = $this->decodeJwtTokenCookie($request);
        } catch (Exception $e) {
            throw new HttpRequestException();
        }

        // We will compare the CSRF token in the decoded API token against the CSRF header
        // sent with the request. If they don't match then this request isn't sent from
        // a valid source and we won't authenticate the request for further handling.
        if (! Passport::$ignoreCsrfToken && (! $this->validCsrf($token, $request) ||
            time() >= $token['expiry'])) {
            throw new TokenExpiredException("Token is invalid or already expired");
        }

        return $token;
    }

    /**
     * Decode and decrypt the JWT token cookie.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function decodeJwtTokenCookie($request)
    {
        return (array) JWT::decode(
            $this->encrypter->decrypt($request->cookie(Passport::cookie()), Passport::$unserializesCookies),
            $this->encrypter->getKey(),
            ['HS256']
        );
    }

    /**
     * Determine if the CSRF / header are valid and match.
     *
     * @param  array  $token
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function validCsrf($token, $request)
    {
        return isset($token['csrf']) && hash_equals(
            $token['csrf'], (string) $request->header('X-CSRF-TOKEN')
        );
    }
}
