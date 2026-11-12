<x-error-page code="419" :title="__('This page has expired')">
    {{ __('It was open for too long before you sent the form. Go back, reload the page and send it again.') }}
    <x-slot:actions>
        <flux:button variant="primary" :href="url()->previous()">{{ __('Go back') }}</flux:button>
    </x-slot:actions>
</x-error-page>
