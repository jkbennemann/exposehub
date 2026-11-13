@php
    $url = \App\Support\Source::url();
    $label = \App\Support\Source::onGitHub() ? __('Source code on GitHub') : __('Source code');
@endphp

{{-- The icon in the header of the public pages that leads to the source. --}}
@if ($url)
    <a
        href="{{ $url }}"
        rel="noopener"
        aria-label="{{ $label }}"
        title="{{ $label }}"
        data-test="source-link"
        {{ $attributes->class('inline-flex size-10 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-900/5 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-cable dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white') }}
    >
        <x-source-mark class="size-5" />
    </a>
@endif
