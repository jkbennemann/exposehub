<?php

namespace App\Support\Sso;

use App\Models\SsoConnection;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single sign-on as it is in force on this install: the identity providers
 * people can sign in through, which accounts each answers for, and which
 * accounts must use one.
 *
 * There are two kinds. The install's own provider is set in the deployment
 * (EXPOSEHUB_SSO_*). A team's provider is entered by the team's owner and
 * counts once a platform admin has approved it; people who sign in through
 * it become members of that team. An email domain belongs to one provider.
 */
final class Sso
{
    private const string SINCE_KEY = 'sso_enabled_at';

    private const string SINCE_CACHE = 'exposehub.sso.since';

    private const string DEFAULT_SCOPES = 'openid email profile';

    /**
     * Every provider in force, the install's own first.
     *
     * @return Collection<int, Connection>
     */
    public static function connections(): Collection
    {
        $connections = collect();
        if (($install = self::installConnection()) !== null) {
            $connections->push($install);
        }

        try {
            $teams = SsoConnection::query()->active()->orderBy('id')->get();
        } catch (Throwable) {
            // Before the table exists (a fresh install during its first migration).
            return $connections;
        }

        $taken = $install->domains ?? [];
        foreach ($teams as $row) {
            // A domain belongs to the first provider that has it; approval
            // refuses a second claim, and this holds if one slips through.
            $domains = array_values(array_diff(self::normalizeDomains($row->domains), $taken));
            if ($domains === []) {
                continue;
            }
            $taken = [...$taken, ...$domains];
            $connections->push(new Connection(
                key: 'team-'.$row->team_id,
                name: $row->name,
                issuer: $row->issuer,
                clientId: $row->client_id,
                clientSecret: $row->client_secret,
                domains: $domains,
                scopes: self::DEFAULT_SCOPES,
                requireVerifiedEmail: $row->require_verified_email,
                teamId: $row->team_id,
                activeSince: $row->approved_at ?? now(),
            ));
        }

        return $connections;
    }

    /**
     * The install's own provider, on once its issuer, its client and the
     * domains it answers for are set.
     */
    public static function installConnection(): ?Connection
    {
        $issuer = (string) config('exposehub.sso.issuer');
        $domains = self::normalizeDomains((array) config('exposehub.sso.domains'));
        if ($issuer === '' || (string) config('exposehub.sso.client_id') === '' || (string) config('exposehub.sso.client_secret') === '' || $domains === []) {
            return null;
        }

        return new Connection(
            key: Connection::INSTALL,
            name: (string) config('exposehub.sso.name'),
            issuer: $issuer,
            clientId: (string) config('exposehub.sso.client_id'),
            clientSecret: (string) config('exposehub.sso.client_secret'),
            domains: $domains,
            scopes: (string) config('exposehub.sso.scopes') ?: self::DEFAULT_SCOPES,
            requireVerifiedEmail: (bool) config('exposehub.sso.require_verified_email'),
            teamId: null,
            activeSince: self::installSince(),
        );
    }

    public static function enabled(): bool
    {
        return self::connections()->isNotEmpty();
    }

    public static function find(string $key): ?Connection
    {
        return self::connections()->first(fn (Connection $connection) => $connection->key === $key);
    }

    /**
     * The provider that answers for this address, if any.
     */
    public static function forEmail(string $email): ?Connection
    {
        return self::connections()->first(fn (Connection $connection) => $connection->answersFor($email));
    }

    /**
     * The provider a person is known at, by the identity they signed in
     * with, or else by their address.
     */
    public static function forUser(User $user): ?Connection
    {
        $issuers = $user->identities()->pluck('issuer')->all();

        return self::connections()->first(fn (Connection $connection) => in_array($connection->issuer, $issuers, true) && $connection->answersFor($user->email))
            ?? self::forEmail($user->email);
    }

    public static function answersFor(string $email): bool
    {
        return self::forEmail($email) !== null;
    }

    /**
     * Whether this account may sign in through its provider only. The people
     * named in EXPOSEHUB_ADMIN_EMAILS keep their password and passkeys, so
     * that someone can get in while a provider is down or set up wrongly.
     */
    public static function requiredFor(User $user): bool
    {
        return ! $user->is_demo
            && ! $user->hasOwnerAddress()
            && self::answersFor($user->email);
    }

    /**
     * The issuers of all providers in force.
     *
     * @return array<int, string>
     */
    public static function issuers(): array
    {
        return self::connections()->pluck('issuer')->unique()->values()->all();
    }

    /**
     * How many days tokens work after the last sign-in through a provider; 0 for good.
     */
    public static function tokenDays(): int
    {
        return (int) config('exposehub.sso.token_days');
    }

    /**
     * Sign-ins through a provider before this moment no longer keep tokens alive.
     */
    public static function tokenCutoff(): ?CarbonInterface
    {
        return self::tokenDays() > 0 ? now()->subDays(self::tokenDays()) : null;
    }

    /**
     * Where providers send people back to; this address is registered with each.
     */
    public static function redirectUri(): string
    {
        return route('sso.callback');
    }

    /**
     * Lower-cased domains without "@", blanks and repeats.
     *
     * @param  array<array-key, mixed>  $domains
     * @return array<int, string>
     */
    public static function normalizeDomains(array $domains): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $domain) => is_string($domain) ? Str::lower(ltrim(trim($domain), '@')) : '',
            $domains,
        ))));
    }

    /**
     * Since when the install's own provider has been on. Accounts it answers
     * for that have not signed in through it yet get their days counted from
     * here, so that switching it on does not cut every tunnel at once. Noted
     * the first time it is asked for while the provider is set.
     */
    private static function installSince(): CarbonInterface
    {
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
     * Forget since when the install's own provider was on, once it is off:
     * switched on again later, the days start anew.
     */
    public static function forgetInstallSince(): void
    {
        DB::table('settings')->where('key', self::SINCE_KEY)->delete();
        Cache::forget(self::SINCE_CACHE);
    }
}
