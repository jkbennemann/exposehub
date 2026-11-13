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
 * manage teams, and to accept an invitation into a real team. Livewire
 * requests are held to the components of the listed pages. What a visitor
 * may do on those pages is decided by the policies, as for any member.
 */
class LimitDemoVisitors
{
    /** Route names a demo account may use. */
    private const array ALLOWED = [
        'home', 'docs', 'agents', 'legal.*',
        'dashboard', 'tunnels.index', 'inspector', 'logs.index', 'tokens.index', 'domains.index', 'access.index',
        'logout',
    ];

    /** The route Livewire sends every component's requests to, whichever page the component is on. */
    private const array LIVEWIRE = ['default-livewire.update', 'livewire.update'];

    /**
     * The Livewire components a demo account may drive: those of the pages
     * above. A component's saved state is signed by the application but not
     * tied to a person, so without this list a visitor could send back the
     * state of the profile page, taken from an account of their own, and
     * change the demo account through it.
     */
    private const array COMPONENTS = [
        'pages::tunnels.index', 'pages::logs.index', 'pages::tokens.index', 'pages::domains.index', 'pages::access.index',
        'team-switcher',
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
        if (in_array($name, self::LIVEWIRE, true) && $this->drivesOnlyListedComponents($request)) {
            return $next($request);
        }

        return $this->refuse($request, redirect()->route('dashboard', ['current_team' => $user->currentTeam])
            ->with('demo', __('That page is not part of the demo. Create an account to use everything.')));
    }

    /**
     * Whether every component a Livewire request addresses is on the list.
     * The name is read the way Livewire reads it, from the state the request
     * sends back; Livewire then checks that state's signature.
     */
    private function drivesOnlyListedComponents(Request $request): bool
    {
        $components = $request->input('components');
        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;
            $state = is_string($snapshot) ? json_decode($snapshot, true) : null;
            $name = is_array($state) && is_array($state['memo'] ?? null) ? ($state['memo']['name'] ?? null) : null;

            if (! is_string($name) || ! in_array($name, self::COMPONENTS, true)) {
                return false;
            }
        }

        return true;
    }

    private function refuse(Request $request, Response $redirect): Response
    {
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(403, __('Not available in the demo.'));
        }

        return $redirect;
    }
}
