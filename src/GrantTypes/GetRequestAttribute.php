<?php
/**
 * Created by PhpStorm.
 * User: bahasolaptop2
 * Date: 17/09/19
 * Time: 14:05
 */

namespace Bahaso\Passport\GrantTypes;


use Psr\Http\Message\ServerRequestInterface;

trait GetRequestAttribute
{
    public function getRequestAttribute($key, ServerRequestInterface $request)
    {
        return $request->getAttribute($key);
    }
}