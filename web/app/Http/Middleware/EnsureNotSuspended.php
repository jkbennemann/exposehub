<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a suspended account on its next request, whichever way it signed
 * in (password, passkey, remember cookie), and says why on the login page.
 */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = __('This account is suspended. Ask the people who run this service if you think this is a mistake.');

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(403, $message);
            }

            return redirect()->route('login')->with('status', $message);
        }

        return $next($request);
    }
}
