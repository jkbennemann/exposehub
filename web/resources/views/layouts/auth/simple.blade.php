@php
    // The address a tunnel gets, without its scheme: the panel has room for the name only.
    $address = preg_replace('#^https?://#', '', (string) config('exposehub.example_address'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <div class="grid min-h-svh lg:grid-cols-[6fr_minmax(30rem,5fr)]">
            {{-- Logo, form and footer share one column and one left edge. --}}
            <div class="mx-auto flex min-h-svh w-full max-w-sm flex-col px-6 py-6 sm:px-0 sm:py-8">
                <x-app-logo href="{{ route('home') }}" wire:navigate />
                <main class="flex flex-1 flex-col justify-center py-10">
                    {{ $slot }}
                </main>
                <x-legal-links />
            </div>

            {{--
                What the account is for, in one picture: the cable from a local port to a
                public address. Always on the ink surface, in either theme. Wide screens only;
                on a phone the form has the page to itself. It stands to the left of the form
                and after it in the document, so the form is what a screen reader meets first.
            --}}
            <aside class="dark hidden flex-col justify-center bg-zinc-950 px-14 py-16 text-white lg:order-first lg:flex xl:px-24" aria-label="{{ __('What a tunnel does') }}" data-test="auth-panel">
                <div class="w-full max-w-2xl">
                    <p class="max-w-[14ch] font-display text-[clamp(2.75rem,1.4rem+2.6vw,4.25rem)] leading-[0.98] font-semibold tracking-tight">
                        {{ __('A public address for a port on your machine') }}
                    </p>

                    {{-- The local end above the cable's start, the public end below its far end: each has the full width for its name. --}}
                    <div class="mt-16">
                        <div class="font-mono text-xl font-medium xl:text-2xl">localhost:8000</div>
                        <div class="mt-1 text-sm text-zinc-400">{{ __('a port on your machine') }}</div>

                        {{-- Jacks match the logo: a yellow plug with an ink core. --}}
                        <div class="my-5 flex items-center" aria-hidden="true">
                            <span class="relative size-4 shrink-0 rounded-full bg-cable after:absolute after:inset-[5px] after:rounded-full after:bg-ink"></span>
                            <span class="wire-line -mx-1 h-1 flex-1 bg-cable"></span>
                            <span class="wire-jack-end relative size-4 shrink-0 rounded-full bg-cable after:absolute after:inset-[5px] after:rounded-full after:bg-ink"></span>
                        </div>

                        <div class="text-end font-mono text-xl font-medium [overflow-wrap:anywhere] xl:text-2xl">{{ $address }}</div>
                        <div class="mt-1 text-end text-sm text-zinc-400">{{ __('an address anyone can open') }}</div>
                    </div>

                    <p class="mt-14 font-mono text-zinc-300"><span class="text-cable" aria-hidden="true">$ </span>exposehub http 8000</p>
                </div>
            </aside>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
