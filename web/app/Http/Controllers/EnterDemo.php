<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class EnterDemo extends Controller
{
    /**
     * Sign a visitor in to the demo, without an account of their own.
     *
     * Every call makes an account, so one address (for IPv6, one /64) gets
     * only a few an hour;
     * the demo as a whole holds a fixed number until its next reset.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(Demo::enabled(), 404);

        // Counted before the account is made, so that requests sent together
        // cannot all slip under the limit.
        $key = 'demo-visitors:'.$this->network((string) $request->ip());
        if (! RateLimiter::attempt($key, (int) config('exposehub.demo.visitors_per_ip_per_hour'), fn () => true, 3600)) {
            return redirect()->route('home')->with('demo', __('You started the demo several times in the last hour. Try again in :minutes minutes.', [
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ]));
        }

        $visitor = Demo::admit();
        if ($visitor === null) {
            return redirect()->route('home')->with('demo', __('The demo is full right now. It starts over every :minutes minutes; try again then.', [
                'minutes' => (int) config('exposehub.demo.reset_minutes'),
            ]));
        }

        Auth::login($visitor);
        $request->session()->regenerate();

        return redirect()->route('dashboard', ['current_team' => $visitor->currentTeam]);
    }

    /**
     * What the limit counts as one visitor: an IPv4 address, or the /64 an
     * IPv6 address is in, since one connection usually has all of those.
     */
    private function network(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;

        return is_string($packed) ? bin2hex(substr($packed, 0, 8)).'::/64' : $ip;
    }
}
