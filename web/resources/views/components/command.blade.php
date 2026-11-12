@props(['command'])

{{-- A line to paste into a terminal, with a copy button. --}}
<div
    data-command
    {{ $attributes->class('flex items-start gap-3 rounded-md bg-zinc-900 px-4 py-3 text-zinc-100 dark:bg-zinc-950 dark:ring-1 dark:ring-zinc-700') }}
    x-data="{ copied: false }"
>
    <code class="min-w-0 flex-1 font-mono text-[0.9375rem] leading-relaxed [overflow-wrap:anywhere]">{{ $command }}</code>
    <button
        type="button"
        class="shrink-0 rounded px-2 py-0.5 text-sm font-medium text-cable hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-cable"
        x-on:click="navigator.clipboard.writeText(@js($command)); copied = true; setTimeout(() => copied = false, 2000)"
        x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"
    >{{ __('Copy') }}</button>
</div>
