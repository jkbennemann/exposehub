<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits requests for a password reset link (POST /forgot-password), per
 * network address and per mailbox, since each one can send a mail.
 *
 * Laravel's password broker only keeps one address from getting a link more
 * than once a minute, and Fortify's route takes no limiter. This runs in the
 * web group and picks the route out by name.
 */
class ThrottlePasswordResetLinks
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('password.email')) {
            return $next($request);
        }

        $budgets = [
            'password-reset-link:ip:'.$request->ip() => (int) config('exposehub.limits.password_reset_links_per_ip_per_hour'),
            'password-reset-link:address:'.Str::lower(Str::trim((string) $request->input('email'))) => (int) config('exposehub.limits.password_reset_links_per_address_per_hour'),
        ];

        foreach ($budgets as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('Too many reset requests. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
                ]);
            }
        }

        foreach (array_keys($budgets) as $key) {
            RateLimiter::hit($key, 3600);
        }

        return $next($request);
    }
}
