<x-error-page code="401" :title="__('Sign in to continue')">
    {{ __('This page is for signed-in people.') }}
    <x-slot:actions>
        <flux:button variant="primary" :href="url('/login')">{{ __('Log in') }}</flux:button>
    </x-slot:actions>
</x-error-page>
