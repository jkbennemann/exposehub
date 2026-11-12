@props(['restricted' => false])

{{--
    The rule of an address at a glance: an open ring for anyone, the same yellow
    lock visitors see on the "limited to certain networks" page for a restriction.
--}}
@if ($restricted)
    <span {{ $attributes->class('flex size-8 shrink-0 items-center justify-center rounded-md bg-cable text-ink') }} title="{{ __('Limited to networks') }}">
        <svg viewBox="0 0 20 20" class="size-4" aria-hidden="true"><path d="M6 9V7a4 4 0 1 1 8 0v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><rect x="4" y="9" width="12" height="9" rx="2" fill="currentColor"/></svg>
    </span>
@else
    <span {{ $attributes->class('flex size-8 shrink-0 items-center justify-center') }} title="{{ __('Anyone') }}">
        <span class="size-4 rounded-full border-2 border-zinc-400 dark:border-zinc-500"></span>
    </span>
@endif
