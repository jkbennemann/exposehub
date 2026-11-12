<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

/**
 * Laravel's password.confirm, but it refuses JSON requests by throwing.
 *
 * Laravel answers a JSON request that needs a fresh confirmation with a 423
 * response. Livewire re-runs this middleware on its update requests and only
 * stops for a redirect: it drops any other response and runs the action
 * anyway. Throwing stops it everywhere.
 */
class RequireRecentPassword extends RequirePassword
{
    /**
     * @param  Request  $request
     * @param  string|null  $redirectToRoute
     * @param  string|int|null  $passwordTimeoutSeconds
     */
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        // Middleware parameters arrive as strings ("password.confirm:,900").
        $timeout = $passwordTimeoutSeconds === null || $passwordTimeoutSeconds === '' ? null : (int) $passwordTimeoutSeconds;

        if ($request->expectsJson() && $this->shouldConfirmPassword($request, $timeout)) {
            abort(423, 'Password confirmation required.');
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
