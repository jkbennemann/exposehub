@php
    $visitor = auth()->user()?->is_demo ? auth()->user() : null;
    $team = $visitor?->currentTeam;
    $minutes = $team?->is_demo
        ? max(1, (int) ceil(now()->diffInSeconds(\App\Support\Demo::resetsAt($team), false) / 60))
        : null;
@endphp

{{-- Tells a visitor of the demo where they are, what is theirs alone, and when all of it goes. --}}
@if ($visitor)
    <aside {{ $attributes->class('rounded-md border border-cable/70 bg-cable/10 px-4 py-3 text-zinc-800 dark:border-cable/40 dark:bg-cable/5 dark:text-zinc-200') }} aria-label="{{ __('About the demo') }}" data-test="demo-banner">
        <p class="max-w-[90ch]">
            <strong class="font-semibold text-zinc-900 dark:text-white">{{ __('This is the demo.') }}</strong>
            {{ __('You are signed in without an account. The tokens you create, and the tunnels and request logs behind them, are visible to you alone; the rest is sample data.') }}
            @if ($minutes !== null)
                {{ trans_choice('{1} Everything here is deleted in about a minute, and your tunnels close with it.|[2,*] Everything here is deleted in about :n minutes, and your tunnels close with it.', $minutes, ['n' => $minutes]) }}
            @endif
        </p>
        <p class="mt-1.5 max-w-[90ch] text-sm text-zinc-600 dark:text-zinc-300">
            {{ __('A demo tunnel gets a random address, shows browsers a short notice before your page, and is limited in how many requests it takes a minute.') }}
            @if (Route::has('register'))
                <a href="{{ route('register') }}" class="font-medium text-zinc-900 underline decoration-cable decoration-2 underline-offset-4 dark:text-white">{{ __('Create an account') }}</a>
                {{ __('to keep an address and use your own domain.') }}
            @endif
        </p>
        @if (session('demo'))
            <p class="mt-2 text-sm font-medium text-zinc-900 dark:text-white" role="status" data-test="demo-notice">{{ session('demo') }}</p>
        @endif
    </aside>
@endif
