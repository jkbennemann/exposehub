@props([
    'title',
    'description',
])

<div class="flex w-full flex-col">
    <h1 class="font-display text-3xl leading-tight font-semibold text-zinc-900 dark:text-white">{{ $title }}</h1>
    <flux:subheading class="mt-1 !text-base">{{ $description }}</flux:subheading>
</div>
