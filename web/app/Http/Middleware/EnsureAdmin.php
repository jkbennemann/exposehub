<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin area answers 404 to everyone else, so it does not announce itself.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel's middleware priority can run this before "auth": guests sign in first.
        if ($request->user() === null) {
            return redirect()->guest(route('login'));
        }

        abort_unless($request->user()->isAdmin(), 404);

        return $next($request);
    }
}
