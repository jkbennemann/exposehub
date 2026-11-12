@props([
    'code',
    'title',
    'fault' => false,
])

{{--
    Every error page. It must render when the database or the session is
    unavailable, so it reads nothing but the request.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="mx-auto flex max-w-7xl items-center px-6 py-5 lg:px-8">
            <x-app-logo href="{{ url('/') }}" />
        </header>

        <main class="mx-auto max-w-7xl px-6 pt-14 pb-24 lg:px-8 lg:pt-24">
            <h1 class="max-w-[18ch] font-display text-[clamp(2.5rem,1.6rem+4vw,4.5rem)] leading-none font-semibold tracking-tight">{{ $title }}</h1>

            <div class="mt-5 max-w-[58ch] text-xl text-zinc-600 dark:text-zinc-300">{{ $slot }}</div>

            {{-- The cable that did not reach: plugged in at your end, loose at ours. --}}
            <div class="mt-14 flex flex-col gap-2 border-y border-zinc-200 py-10 sm:flex-row sm:items-center sm:gap-4 dark:border-zinc-700">
                <div class="shrink-0">
                    <div class="font-mono text-lg font-medium sm:text-2xl">{{ __('your browser') }}</div>
                </div>

                <div class="ms-2 flex flex-col items-center sm:ms-0 sm:min-w-24 sm:flex-1 sm:flex-row" aria-hidden="true">
                    <span class="relative size-4 shrink-0 rounded-full bg-cable after:absolute after:inset-[5px] after:rounded-full after:bg-ink"></span>
                    <span class="-my-1 h-8 w-1 bg-cable sm:-mx-1 sm:my-0 sm:h-1 sm:w-auto sm:flex-1"></span>
                    <span class="size-2 shrink-0 rounded-full bg-cable"></span>
                    <span class="h-6 w-1 sm:h-1 sm:w-16"></span>
                    <span @class([
                        'size-4 shrink-0 rounded-full border-[3px]',
                        'border-fault' => $fault,
                        'border-zinc-400 dark:border-zinc-500' => ! $fault,
                    ])></span>
                </div>

                <div class="min-w-0">
                    <div class="font-mono text-lg font-medium [overflow-wrap:anywhere] sm:text-2xl">/{{ ltrim(request()->path(), '/') }}</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Error :code', ['code' => $code]) }}</div>
                </div>
            </div>

            <div class="mt-10 flex flex-wrap gap-3">
                {{ $actions ?? '' }}
                <flux:button :variant="isset($actions) ? 'filled' : 'primary'" :href="url('/')">{{ __('Go to the start page') }}</flux:button>
            </div>
        </main>
    </body>
</html>
