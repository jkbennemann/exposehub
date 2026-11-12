@props(['title', 'lead' => null])

@php
    $items = [
        ['route' => 'admin.overview', 'label' => __('Overview'), 'match' => 'admin.overview'],
        ['route' => 'admin.people', 'label' => __('People'), 'match' => 'admin.people*'],
        ['route' => 'admin.person', 'label' => null, 'match' => 'admin.person'],
        ['route' => 'admin.teams', 'label' => __('Teams'), 'match' => 'admin.teams'],
        ['route' => 'admin.tunnels', 'label' => __('Live tunnels'), 'match' => 'admin.tunnels'],
        ['route' => 'admin.settings', 'label' => __('Settings'), 'match' => 'admin.settings'],
        ['route' => 'admin.audit', 'label' => __('Audit log'), 'match' => 'admin.audit'],
    ];
    // A person's page belongs to People.
    $current = fn (array $item) => request()->routeIs($item['match']) || ($item['route'] === 'admin.people' && request()->routeIs('admin.person'));
@endphp

{{--
    The operator rail: always dark, in either theme, so admin mode is never mistaken
    for the team pages. The sections sit on one cable like jacks; the open one is plugged in.
--}}
<div {{ $attributes->class('admin') }}>
    <div class="rounded-md bg-zinc-950 px-5 pt-4 pb-3 text-zinc-100 ring-1 ring-zinc-800 sm:px-7" data-test="admin-rail">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <p class="flex items-center gap-2.5 font-display text-lg font-semibold">
                <x-app-logo-icon class="h-3.5 w-7" />
                {{ __('Admin') }}
                @if (auth()->user()->isOwner())
                    <span class="font-sans text-sm font-normal text-zinc-400">{{ __('platform owner') }}</span>
                @endif
            </p>
            <p class="text-sm text-zinc-400">{{ __('Every change here is recorded in the audit log, with your name.') }}</p>
        </div>

        <nav class="admin-jacks -mx-1 mt-4 overflow-x-auto pb-1" aria-label="{{ __('Admin') }}">
            <ul class="relative flex w-max items-start gap-1">
                @foreach ($items as $item)
                    @continue($item['label'] === null)
                    @php $on = $current($item); @endphp
                    <li class="relative">
                        <a href="{{ route($item['route']) }}" wire:navigate @if ($on) aria-current="page" @endif
                           class="group flex flex-col items-start gap-2 rounded px-3 py-1 focus-visible:outline-2 focus-visible:outline-cable">
                            <span @class(['admin-jack', 'is-on' => $on])></span>
                            <span @class(['text-[0.95rem] whitespace-nowrap', 'font-medium text-white' => $on, 'text-zinc-400 group-hover:text-zinc-100' => ! $on])>{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>

    <header class="mt-10">
        <h1 class="page-title">{{ $title }}</h1>
        @if ($lead)
            <p class="mt-3 max-w-[64ch] text-zinc-600 dark:text-zinc-300">{{ $lead }}</p>
        @endif
    </header>

    <div class="mt-8">
        {{ $slot }}
    </div>
</div>
