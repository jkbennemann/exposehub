<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTunnelNode
{
    /**
     * Let the request through only if it carries the tunnel server's shared secret.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('exposehub.node_secret');

        abort_if(
            $secret === '' || ! hash_equals($secret, (string) $request->bearerToken()),
            401,
        );

        return $next($request);
    }
}
