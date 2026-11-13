@props([
    'title',
    'description' => null,
])

<div class="flex w-full flex-col">
    <h1 class="font-display text-4xl leading-none font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h1>
    @if ($description)
        <flux:subheading class="mt-3 !text-base">{{ $description }}</flux:subheading>
    @endif
</div>
