@props([
    'sidebar' => false,
])

<a {{ $attributes->class('flex items-center gap-2.5 text-zinc-900 dark:text-white') }}>
    <x-app-logo-icon class="h-4 w-8 shrink-0" />
    <span class="font-display text-[1.35rem] leading-none font-semibold tracking-tight">{{ config('app.name') }}</span>
</a>
