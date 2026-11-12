<?php

use App\Concerns\ProfileValidationRules;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        if (config('fortify.lowercase_usernames')) {
            $this->email = Str::lower($this->email);
        }

        $validated = $this->validate($this->profileRules($user->id));

        if ($validated['email'] !== $user->email) {
            // A change mails both the old and the new address, and this page needs
            // no confirmed address: without a limit any account could mail anyone.
            $this->throttle(['profile-email-change:user:'.$user->id => 3, 'profile-email-change:ip:'.request()->ip() => 10], 3600);
        }

        $user->fill($validated);

        $previousEmail = $user->isDirty('email') ? $user->getOriginal('email') : null;
        if ($previousEmail !== null) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($previousEmail !== null) {
            // The old address hears about it: if the change was not the owner's, it is the only warning they get.
            AccountMail::send($previousEmail, AccountNotice::emailChanged($user->email)->addressedTo($user->name));

            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $e) {
                report($e);
            }
        }

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        // The same budget as Fortify's resend route (throttle:6,1), which keys
        // it by the signed-in user's id, hashed.
        $this->throttle([sha1((string) $user->getAuthIdentifier()) => 6], 60);

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Count one mail-sending action against each budget, or refuse it with an
     * error on the address field when one is used up. Counted per call, so a
     * request that calls an action many times is bounded too.
     *
     * @param  array<string, int>  $budgets  maximum attempts by limiter key
     */
    private function throttle(array $budgets, int $decaySeconds): void
    {
        foreach ($budgets as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('Too many emails sent. Try again in :minutes minutes.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]),
                ]);
            }
        }

        foreach (array_keys($budgets) as $key) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
