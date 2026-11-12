<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks the password of someone who is already signed in: the password
 * confirmation, deleting the account and changing the password.
 *
 * A session alone must not be enough to guess the password, so failed checks
 * share one budget per account, like sign-in has. While it is used up even
 * the right password is refused.
 */
final class PasswordAttempts
{
    public static function check(User $user, ?string $password): bool
    {
        if (self::locked($user)) {
            return false;
        }

        if (is_string($password) && $password !== '' && Hash::check($password, $user->getAuthPassword())) {
            RateLimiter::clear(self::key($user));

            return true;
        }

        RateLimiter::hit(self::key($user), 60);

        return false;
    }

    public static function locked(User $user): bool
    {
        return RateLimiter::tooManyAttempts(self::key($user), (int) config('exposehub.limits.password_checks_per_minute'));
    }

    /**
     * Seconds until the account may try again.
     */
    public static function availableIn(User $user): int
    {
        return RateLimiter::availableIn(self::key($user));
    }

    private static function key(User $user): string
    {
        return 'password-checks:'.$user->getKey();
    }
}
