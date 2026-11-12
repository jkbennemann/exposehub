<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's routes that add or change passkeys and two-factor authentication
 * require a confirmed email address, like the security page that uses them.
 *
 * Otherwise someone who signed up with another person's address could add a
 * passkey before that person claims the account, and keep signing in with it
 * after they reset the password. Fortify offers no way to add middleware to
 * just these routes, so this runs in the web group and picks them out by name.
 */
class EnsureVerifiedForAccountSecurity
{
    private const array ROUTES = [
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Guests are left to the routes' own "auth" middleware, which sends them to sign in.
        if ($request->routeIs(...self::ROUTES) && $request->user()?->hasVerifiedEmail() === false) {
            abort(403, __('Confirm your email address first.'));
        }

        return $next($request);
    }
}
