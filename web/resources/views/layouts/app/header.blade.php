@php
    $nav = [
        ['label' => __('Overview'), 'route' => 'dashboard', 'icon' => 'home'],
        ['label' => __('Tunnels'), 'route' => 'tunnels.index', 'icon' => 'signal'],
        ['label' => __('Request logs'), 'route' => 'logs.index', 'icon' => 'chart-bar'],
        ['label' => __('Inspector'), 'route' => 'inspector', 'icon' => 'queue-list'],
        ['label' => __('Agent tokens'), 'route' => 'tokens.index', 'icon' => 'key'],
        ['label' => __('Domains'), 'route' => 'domains.index', 'icon' => 'globe-alt'],
        ['label' => __('Access'), 'route' => 'access.index', 'icon' => 'lock-closed'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <flux:header container class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

            <div class="ms-6 max-lg:hidden">
                <livewire:team-switcher />
            </div>

            <flux:spacer />

            {{-- The cable under the current item is the same one that joins the two ends of a tunnel. --}}
            <nav class="me-4 flex h-full items-stretch gap-1 max-lg:hidden" aria-label="{{ __('Main') }}">
                @foreach ($nav as $item)
                    @php $current = request()->routeIs($item['route']); @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        @if ($current) aria-current="page" @endif
                        @class([
                            'relative flex items-center px-2 font-display text-base font-medium tracking-tight whitespace-nowrap xl:px-3 xl:text-lg focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent-content',
                            'text-zinc-900 dark:text-white' => $current,
                            'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => ! $current,
                        ])
                    >
                        {{ $item['label'] }}
                        @if ($current)
                            <span class="absolute inset-x-2 -bottom-[2px] h-[3px] rounded-full bg-cable xl:inset-x-3"></span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <x-desktop-user-menu :showTeam="false" />
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <flux:sidebar.nav>
                @foreach ($nav as $item)
                    <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['route'])" wire:navigate>
                        {{ $item['label'] }}
                    </flux:sidebar.item>
                @endforeach
                @if (auth()->user()->isAdmin())
                    <flux:sidebar.item icon="shield-check" :href="route('admin.overview')" :current="request()->routeIs('admin.*')" wire:navigate>
                        {{ __('Admin') }}
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
