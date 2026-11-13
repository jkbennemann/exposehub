@php
    $pages = \App\Support\LegalPage::published();
    $sourceUrl = \App\Support\Source::url();
    $version = \App\Support\Source::version();
@endphp

{{-- The docs, the operator's legal texts once the operator provides them, and
     the source code of the running version. --}}
<footer {{ $attributes->class('flex flex-wrap gap-x-6 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400') }}>
    <a href="{{ route('docs') }}" class="underline-offset-4 hover:text-zinc-900 hover:underline dark:hover:text-white">{{ __('Docs') }}</a>
    @foreach ($pages as $page)
        <a href="{{ route('legal.'.$page->value) }}" class="underline-offset-4 hover:text-zinc-900 hover:underline dark:hover:text-white">{{ $page->title() }}</a>
    @endforeach
    @if ($sourceUrl)
        <a href="{{ $sourceUrl }}" rel="noopener" class="underline-offset-4 hover:text-zinc-900 hover:underline dark:hover:text-white"><x-source-mark class="mr-1.5 inline size-3.5 align-[-0.125em]" />{{ __('Source') }}@if ($version !== '')<span class="text-zinc-400 dark:text-zinc-500"> · {{ $version }}</span>@endif</a>
    @endif
</footer>
