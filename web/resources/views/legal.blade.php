<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $page->title()])
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5 lg:px-8">
            <x-app-logo href="{{ route('home') }}" />
        </header>

        <main class="mx-auto max-w-7xl px-6 pt-8 pb-16 lg:px-8 lg:pt-14">
            <article class="legal">
                {!! $page->html() !!}
            </article>
        </main>

        <x-legal-links class="mx-auto max-w-7xl px-6 pb-10 lg:px-8" />

        @fluxScripts
    </body>
</html>
