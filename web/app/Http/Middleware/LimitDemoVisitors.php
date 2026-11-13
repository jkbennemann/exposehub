<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an account of the demo to the pages the demo is about.
 *
 * The list names what a visitor may open; everything else is refused, also
 * pages added later. Refused with it are all the ways to change an account
 * (name, address, password, passkeys, two-factor, deletion), to make or
 * manage teams, and to accept an invitation into a real team. What a visitor
 * may do on the pages that are listed is decided by the policies, as for
 * any member.
 */
class LimitDemoVisitors
{
    /** Route names a demo account may use. */
    private const array ALLOWED = [
        'home', 'docs', 'legal.*',
        'dashboard', 'tunnels.index', 'inspector', 'logs.index', 'tokens.index', 'domains.index', 'access.index',
        'logout',
        // The requests Livewire sends for the pages above.
        'default-livewire.update', 'livewire.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->is_demo) {
            return $next($request);
        }

        // The demo was switched off while this visitor was in it.
        if (! Demo::enabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->refuse($request, redirect()->route('home'));
        }

        $name = $request->route()?->getName();
        if ($name !== null && Str::is(self::ALLOWED, $name)) {
            return $next($request);
        }

        return $this->refuse($request, redirect()->route('dashboard', ['current_team' => $user->currentTeam])
            ->with('demo', __('That page is not part of the demo. Create an account to use everything.')));
    }

    private function refuse(Request $request, Response $redirect): Response
    {
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(403, __('Not available in the demo.'));
        }

        return $redirect;
    }
}
