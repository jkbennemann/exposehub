<?php

namespace App\Concerns;

use App\Support\PasswordAttempts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * Failed checks count against the account's budget, shared with the
     * password confirmation, so an open session cannot guess the password.
     *
     * @return array<int, Password|ValidationRule|Closure|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
            $user = Auth::user();
            if ($user === null) {
                $fail(__('validation.current_password'));

                return;
            }
            if (PasswordAttempts::locked($user)) {
                $fail(__('Too many wrong passwords. Try again in :seconds seconds.', ['seconds' => PasswordAttempts::availableIn($user)]));

                return;
            }
            if (! PasswordAttempts::check($user, is_string($value) ? $value : null)) {
                $fail(__('validation.current_password'));
            }
        }];
    }
}
