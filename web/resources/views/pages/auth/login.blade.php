<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Log in')" />
        @endif

        {{--
            Single sign-on. The install's own provider gets a button. A team's provider is found
            by the address: which teams have one is nobody else's business. Accounts of a
            provider's domains get in this way only.
        --}}
        @php
            $providers = \App\Support\Sso\Sso::connections();
            $own = $providers->first(fn ($provider) => $provider->teamId === null);
            $ofTeams = $providers->contains(fn ($provider) => $provider->teamId !== null);
        @endphp
        @if ($providers->isNotEmpty())
            <div class="flex flex-col gap-3" data-test="sso-sign-in">
                @if ($own)
                    <flux:button variant="primary" class="w-full" :href="route('sso.redirect')" data-test="sso-button">
                        {{ __('Sign in with :name', ['name' => $own->name]) }}
                    </flux:button>
                @endif
                @if ($ofTeams)
                    <form method="GET" action="{{ route('sso.redirect') }}" class="flex flex-col gap-3" data-test="sso-by-address">
                        <flux:input name="address" type="email" :label="__('Work email address')" required autocomplete="email" placeholder="you@company.example" />
                        <flux:button type="submit" :variant="$own ? 'filled' : 'primary'" class="w-full" data-test="sso-address-button">
                            {{ __('Continue with single sign-on') }}
                        </flux:button>
                    </form>
                @endif
                @error('sso')<p class="text-center text-sm text-fault" role="alert" data-test="sso-error">{{ $message }}</p>@enderror
                <p class="text-center text-sm text-zinc-600 dark:text-zinc-400">{{ __('For accounts your organisation signs in. Everyone else uses a passkey or password below.') }}</p>
            </div>
            <flux:separator :text="__('or')" />
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link
                :href="$teamInvitation ? route('register', ['invitation' => $teamInvitation['code']]) : route('register')"
                data-test="register-link"
                wire:navigate
            >
                {{ __('Sign up') }}
            </flux:link>
        </div>
    </div>
</x-layouts::auth>
