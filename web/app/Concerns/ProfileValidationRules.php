<?php

namespace App\Concerns;

use App\Models\User;
use App\Rules\AllowedEmailDomain;
use App\Support\Sso\Sso;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, Closure|Unique|ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, Closure|Unique|ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, Closure|Unique|ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
            new AllowedEmailDomain(except: $userId === null ? null : User::find($userId)?->email),
            $this->leavesAddressesToTheProvider($userId),
        ];
    }

    /**
     * With single sign-on, addresses of a provider's domains are that
     * provider's to give out. Nobody registers one or moves an account to
     * one: such an account would sit there with a password its maker knows,
     * waiting for the address's real holder to be signed in to it. And an
     * account that signs in through the provider keeps the address it has
     * there. The people who run the install are exempt, so that they can
     * make the accounts that still get in when the provider is down.
     */
    private function leavesAddressesToTheProvider(?int $userId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($userId) {
            if (! Sso::enabled() || ! is_string($value)) {
                return;
            }
            $user = $userId === null ? null : User::find($userId);
            $email = Str::lower($value);
            if ($user !== null && $email === Str::lower($user->email)) {
                return;
            }

            if ($user?->identities()->exists()) {
                $fail(__('Your address comes from :name. Change it there.', ['name' => Sso::forUser($user)->name ?? __('single sign-on')]));
            } elseif (($connection = Sso::forEmail($email)) !== null && ! in_array($email, config('exposehub.admin_emails', []), true)) {
                $fail($user === null
                    ? __('Accounts with an address of this domain are made by signing in with :name.', ['name' => $connection->name])
                    : __('Addresses of this domain belong to accounts that sign in with :name.', ['name' => $connection->name]));
            }
        };
    }
}
