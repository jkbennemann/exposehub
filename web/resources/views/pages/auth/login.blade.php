@php
    // Single sign-on. The install's own provider gets a button. A team's provider is found
    // by the address: which teams have one is nobody else's business. Accounts of a
    // provider's domains get in this way only.
    $providers = \App\Support\Sso\Sso::connections();
    $own = $providers->first(fn ($provider) => $provider->teamId === null);
    $ofTeams = $providers->contains(fn ($provider) => $provider->teamId !== null);

    // Someone whose attempt by address failed comes back to where they were.
    $startByAddress = $ofTeams && ! $own && $errors->has('sso');
@endphp
<x-layouts::auth :title="__('Log in')">
    {{--
        One way in at a time. The page opens on email and password, with the other ways
        below the form; single sign-on by address has a view of its own, so that nobody
        faces two email fields at once.
    --}}
    <div
        x-data="{
            byAddress: @js($startByAddress),
            show(byAddress) {
                const from = this.$root.querySelector(byAddress ? '[name=email]' : '[name=address]');
                const to = this.$root.querySelector(byAddress ? '[name=address]' : '[name=email]');
                if (from && to && ! to.value) {
                    to.value = from.value;
                }
                this.byAddress = byAddress;
                this.$nextTick(() => to?.focus());
            },
        }"
    >
        <div x-show="! byAddress" @if ($startByAddress) x-cloak @endif class="flex flex-col gap-6" data-test="login-view">
            <x-auth-header :title="__('Log in')" />

            <x-auth-session-status :status="session('status')" />

            @if ($teamInvitation)
                <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Log in')" />
            @endif

            @if ($own)
                <div class="flex flex-col gap-3" data-test="sso-sign-in">
                    <flux:button variant="primary" class="w-full" :href="route('sso.redirect')" data-test="sso-button">
                        {{ __('Sign in with :name', ['name' => $own->name]) }}
                    </flux:button>
                    @error('sso')<p class="text-sm text-fault" role="alert" data-test="sso-error">{{ $message }}</p>@enderror
                </div>
                <flux:separator :text="__('or with a password')" />
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
                @csrf

                <flux:input
                    name="email"
                    :label="__('Email address')"
                    :value="old('email')"
                    type="email"
                    required
                    :autofocus="! $startByAddress"
                    autocomplete="email"
                />

                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    viewable
                />

                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

                    @if (Route::has('password.request'))
                        <flux:link class="text-sm" :href="route('password.request')" wire:navigate>
                            {{ __('Forgot your password?') }}
                        </flux:link>
                    @endif
                </div>

                {{-- One yellow button a view: where the install has its own provider, that is the one. --}}
                <flux:button :variant="$own ? 'filled' : 'primary'" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </form>

            {{-- The other ways in. The passkey button shows only where the browser has passkeys. --}}
            <div class="flex flex-col gap-3">
                @if ($ofTeams)
                    <flux:separator :text="__('or')" class="mb-3" />
                @endif

                <x-passkey-verify :separator="$ofTeams ? false : __('or')" separator-above />

                @if ($ofTeams)
                    <flux:button variant="outline" icon="building-office-2" class="w-full" x-on:click="show(true)" data-test="sso-switch">
                        {{ __('Use single sign-on') }}
                    </flux:button>
                @endif
            </div>

            @if (Route::has('register'))
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('Don\'t have an account?') }}
                    <flux:link
                        :href="$teamInvitation ? route('register', ['invitation' => $teamInvitation['code']]) : route('register')"
                        data-test="register-link"
                        wire:navigate
                    >{{ __('Sign up') }}</flux:link>
                </p>
            @endif
        </div>

        @if ($ofTeams)
            <div x-show="byAddress" @unless ($startByAddress) x-cloak @endunless class="flex flex-col gap-6" data-test="sso-sign-in">
                <x-auth-header
                    :title="__('Single sign-on')"
                    :description="__('For accounts your organisation signs in. Enter your work email address and continue on your organisation\'s sign-in page.')"
                />

                <form method="GET" action="{{ route('sso.redirect') }}" class="flex flex-col gap-5" data-test="sso-by-address">
                    <flux:input
                        name="address"
                        type="email"
                        :label="__('Work email address')"
                        required
                        :autofocus="$startByAddress"
                        autocomplete="email"
                        placeholder="you@company.example"
                    />

                    @unless ($own)
                        @error('sso')<p class="text-sm text-fault" role="alert" data-test="sso-error">{{ $message }}</p>@enderror
                    @endunless

                    <flux:button type="submit" variant="primary" class="w-full" data-test="sso-address-button">
                        {{ __('Continue') }}
                    </flux:button>
                </form>

                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('No single sign-on?') }}
                    <flux:link as="button" type="button" x-on:click="show(false)" data-test="sso-back">{{ __('Log in with a password or passkey') }}</flux:link>
                </p>
            </div>
        @endif
    </div>
</x-layouts::auth>
