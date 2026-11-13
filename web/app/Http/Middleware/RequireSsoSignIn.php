<?php

namespace App\Http\Middleware;

use App\Support\Sso\Sso;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session that did not begin at the identity provider, for an account
 * that may sign in through it only (see App\Support\Sso\Sso::requiredFor).
 *
 * One check for every way in: a password, a passkey, a two-factor code, a
 * "remember me" cookie, and sessions that were open when single sign-on was
 * switched on. It runs after the sign-in went through, so it tells nobody
 * who did not know the password that an account exists.
 */
class RequireSsoSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The mark names the account it was given to: left behind in a session, it is no good to the next one.
        if ($user === null || ! Sso::requiredFor($user) || $request->session()->get('sso.signed_in') === $user->id) {
            return $next($request);
        }

        $message = __('Your account signs in with :name. Use single sign-on on this page.', ['name' => Sso::forEmail($user->email)->name ?? __('single sign-on')]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(403, $message);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
