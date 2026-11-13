<x-layouts::auth :title="__('Confirm password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm password')"
            :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- An account that signs in through the provider has no password to type: it proves itself there again. --}}
        @if (\App\Support\Sso\Sso::enabled() && auth()->user()?->identities()->exists())
            <div class="flex flex-col gap-3" data-test="sso-confirm">
                <flux:button variant="primary" class="w-full" :href="route('sso.redirect', ['confirm' => 1])" data-test="sso-confirm-button">
                    {{ __('Confirm with :name', ['name' => \App\Support\Sso\Sso::name()]) }}
                </flux:button>
                <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">{{ __('You will be asked to sign in there again.') }}</p>
                @error('password')<p class="text-center text-sm text-fault">{{ $message }}</p>@enderror
            </div>
            <flux:separator :text="__('or')" />
        @endif

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
