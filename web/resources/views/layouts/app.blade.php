<x-layouts::app.header :title="$title ?? null">
    <flux:main container class="!py-10 lg:!py-14">
        <x-demo-banner class="mb-10" />

        {{ $slot }}

        <x-legal-links class="mt-16 border-t border-zinc-200 pt-6 dark:border-zinc-700" />
    </flux:main>
</x-layouts::app.header>
