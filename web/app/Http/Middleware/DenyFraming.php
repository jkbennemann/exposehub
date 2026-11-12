<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No other site may show the dashboard in a frame, so nobody can trick a
 * signed-in person into clicking a button they cannot see (clickjacking).
 */
class DenyFraming
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY', replace: false);

        $policy = $response->headers->get('Content-Security-Policy');
        if ($policy === null) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        } elseif (! str_contains($policy, 'frame-ancestors')) {
            $response->headers->set('Content-Security-Policy', rtrim($policy, '; ')."; frame-ancestors 'none'");
        }

        return $response;
    }
}
