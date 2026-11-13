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
     * Every call makes an account, so one address gets only a few an hour;
     * the demo as a whole holds a fixed number until its next reset.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(Demo::enabled(), 404);

        $key = 'demo-visitors:'.$request->ip();
        $perHour = (int) config('exposehub.demo.visitors_per_ip_per_hour');
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
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
        RateLimiter::hit($key, 3600);

        Auth::login($visitor);
        $request->session()->regenerate();

        return redirect()->route('dashboard', ['current_team' => $visitor->currentTeam]);
    }
}
