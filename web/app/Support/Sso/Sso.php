<?php

namespace App\Support\Sso;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single sign-on as the operator set it up (EXPOSEHUB_SSO_*): whether it is
 * on, which accounts it answers for, and which of them must use it.
 */
final class Sso
{
    private const string SINCE_KEY = 'sso_enabled_at';

    private const string SINCE_CACHE = 'exposehub.sso.since';

    /**
     * On once the provider, the client and the domains it answers for are set.
     */
    public static function enabled(): bool
    {
        return self::issuer() !== ''
            && (string) config('exposehub.sso.client_id') !== ''
            && (string) config('exposehub.sso.client_secret') !== ''
            && self::domains() !== [];
    }

    public static function issuer(): string
    {
        return (string) config('exposehub.sso.issuer');
    }

    /**
     * What the provider is called on buttons and in messages.
     */
    public static function name(): string
    {
        return (string) config('exposehub.sso.name');
    }

    /**
     * The email domains the provider answers for, lower-cased.
     *
     * @return array<int, string>
     */
    public static function domains(): array
    {
        return array_values(array_filter((array) config('exposehub.sso.domains'), is_string(...)));
    }

    /**
     * Whether the provider answers for this address.
     */
    public static function answersFor(string $email): bool
    {
        $domain = Str::lower(Str::afterLast($email, '@'));

        return str_contains($email, '@') && in_array($domain, self::domains(), true);
    }

    /**
     * Whether this account may sign in through the provider only. The people
     * named in EXPOSEHUB_ADMIN_EMAILS keep their password and passkeys, so
     * that someone can get in while the provider is down or set up wrongly.
     */
    public static function requiredFor(User $user): bool
    {
        return self::enabled()
            && ! $user->is_demo
            && ! $user->hasOwnerAddress()
            && self::answersFor($user->email);
    }

    /**
     * How many days tokens work after the last sign-in through the provider; 0 for good.
     */
    public static function tokenDays(): int
    {
        return self::enabled() ? (int) config('exposehub.sso.token_days') : 0;
    }

    /**
     * Sign-ins through the provider before this moment no longer keep tokens alive.
     */
    public static function tokenCutoff(): ?CarbonInterface
    {
        return self::tokenDays() > 0 ? now()->subDays(self::tokenDays()) : null;
    }

    /**
     * Where the provider sends people back to; this address is registered with it.
     */
    public static function redirectUri(): string
    {
        return route('sso.callback');
    }

    /**
     * Since when single sign-on has been on. Accounts it answers for that
     * have not signed in through it yet get their days counted from here, so
     * that switching it on does not cut every tunnel at once. Noted the first
     * time it is asked for while single sign-on is on.
     */
    public static function since(): ?CarbonInterface
    {
        if (! self::enabled()) {
            return null;
        }

        try {
            $since = Cache::rememberForever(self::SINCE_CACHE, function () {
                $stored = DB::table('settings')->where('key', self::SINCE_KEY)->value('value');
                if ($stored === null) {
                    $stored = json_encode(now()->toIso8601String());
                    DB::table('settings')->insertOrIgnore([
                        'key' => self::SINCE_KEY, 'value' => $stored, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $stored = DB::table('settings')->where('key', self::SINCE_KEY)->value('value') ?? $stored;
                }

                return json_decode((string) $stored, true);
            });

            return is_string($since) ? Carbon::parse($since) : now();
        } catch (Throwable $e) {
            report($e);

            // Without the date nobody's days can be counted; err towards letting tokens work.
            return now();
        }
    }

    /**
     * Forget since when it was on, once it is off: switched on again later,
     * the days start anew.
     */
    public static function forgetSince(): void
    {
        DB::table('settings')->where('key', self::SINCE_KEY)->delete();
        Cache::forget(self::SINCE_CACHE);
    }
}
