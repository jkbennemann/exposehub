@props([
    'from',
    'fromNote' => null,
    'to',
    'toNote' => null,
])

{{-- A connection, drawn the way it is patched: local end, cable, public end. --}}
<div {{ $attributes->class('flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4') }}>
    <div class="shrink-0">
        <div class="font-mono text-lg font-medium sm:text-2xl">{{ $from }}</div>
        @if ($fromNote)
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $fromNote }}</div>
        @endif
    </div>

    <div class="ms-2 flex flex-col items-center sm:ms-0 sm:min-w-16 sm:flex-1 sm:flex-row" aria-hidden="true">
        {{-- Jacks match the logo: a yellow plug with an ink core. --}}
        <span class="relative size-4 shrink-0 rounded-full bg-cable after:absolute after:inset-[5px] after:rounded-full after:bg-ink"></span>
        <span class="wire-line -my-1 h-10 w-1 bg-cable sm:-mx-1 sm:my-0 sm:h-1 sm:w-auto sm:flex-1"></span>
        <span class="wire-jack-end relative size-4 shrink-0 rounded-full bg-cable after:absolute after:inset-[5px] after:rounded-full after:bg-ink"></span>
    </div>

    <div class="min-w-0">
        <div class="font-mono text-lg font-medium [overflow-wrap:anywhere] sm:text-2xl">{{ $to }}</div>
        @if ($toNote)
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $toNote }}</div>
        @endif
    </div>
</div>
