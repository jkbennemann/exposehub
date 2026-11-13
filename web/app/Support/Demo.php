<?php

namespace App\Support;

use App\Enums\TeamRole;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\LogGrant;
use App\Models\RequestLog;
use App\Models\SavedAddress;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The demo (EXPOSEHUB_DEMO): one team that holds sample data, which anyone
 * can enter without an account, and which is deleted and laid out again
 * every few minutes.
 *
 * Each visitor is an account of their own, a plain member of that team, made
 * when they enter and deleted with the team. That is what keeps visitors
 * apart: a member sees their own tokens, tunnels and request logs and nobody
 * else's, by the same rules as in any other team, and may not change what the
 * team shares. The sample data belongs to the team's owner, an account nobody
 * can sign in to, who shares its request logs with every visitor.
 *
 * Demo accounts end in "@demo.invalid", a domain that cannot receive mail,
 * and what they may open is limited by App\Http\Middleware\LimitDemoVisitors.
 */
final class Demo
{
    /** Serialises resets: the scheduler and a visitor may both find one due. */
    private const string LOCK = 'exposehub.demo.reset';

    private const string MAIL_DOMAIN = 'demo.invalid';

    public static function enabled(): bool
    {
        return (bool) config('exposehub.demo.enabled');
    }

    public static function team(): ?Team
    {
        return Team::query()->where('is_demo', true)->oldest('id')->first();
    }

    /**
     * When the demo is wiped next: a fixed time after it was laid out.
     */
    public static function resetsAt(Team $team): CarbonInterface
    {
        return ($team->created_at ?? now())->toImmutable()->addMinutes((int) config('exposehub.demo.reset_minutes'));
    }

    /**
     * Whether a reset has work to do: the demo is on and missing or past its
     * time, or it is off and something of it is still there.
     */
    public static function due(): bool
    {
        $team = self::team();

        if (! self::enabled()) {
            return $team !== null || User::query()->where('is_demo', true)->exists();
        }

        return $team === null || now()->greaterThanOrEqualTo(self::resetsAt($team));
    }

    /**
     * Delete the demo team, its visitors and everything they made, and, while
     * the demo is on, lay the sample data out again. The tunnel server closes
     * the tunnels of the deleted tokens at its next sync.
     */
    public static function reset(): void
    {
        Cache::lock(self::LOCK, 60)->get(function () {
            DB::transaction(function () {
                self::wipe();

                if (self::enabled()) {
                    self::layOut();
                }
            });
        });
    }

    /**
     * Make an account for a visitor and put it in the demo team. Null when
     * the demo is off or already holds as many visitors as it may.
     */
    public static function admit(): ?User
    {
        if (! self::enabled()) {
            return null;
        }
        if (self::due()) {
            self::reset();
        }

        return DB::transaction(function () {
            // Locked, so that two visitors arriving together are counted one after the other.
            $team = Team::query()->where('is_demo', true)->lockForUpdate()->oldest('id')->first();
            $keeper = $team?->owner();
            if ($team === null || ! $keeper instanceof User) {
                return null;
            }
            // The keeper of the sample data is a member too.
            if ($team->memberships()->count() > (int) config('exposehub.demo.max_visitors')) {
                return null;
            }

            $visitor = self::account(__('Demo visitor'), 'visitor-'.Str::lower((string) Str::ulid()), $team);
            $team->memberships()->create(['user_id' => $visitor->id, 'role' => TeamRole::Member]);
            LogGrant::query()->create(['team_id' => $team->id, 'owner_id' => $keeper->id, 'viewer_id' => $visitor->id]);

            return $visitor;
        });
    }

    private static function wipe(): void
    {
        foreach (Team::withTrashed()->where('is_demo', true)->get() as $team) {
            LogGrant::forgetTeam($team);
            // Tokens, saved addresses, networks, domains, memberships and
            // invitations go with the team. Nothing was set up outside the
            // database for its sample domain, so there is nothing to remove there.
            $team->forceDelete();
        }

        $accounts = User::query()->where('is_demo', true)->pluck('id');
        if ($accounts->isEmpty()) {
            return;
        }
        if (config('session.driver') === 'database' && Schema::hasTable((string) config('session.table'))) {
            DB::table((string) config('session.table'))->whereIn('user_id', $accounts)->delete();
        }
        User::query()->whereIn('id', $accounts)->delete();
    }

    private static function layOut(): void
    {
        $team = new Team(['name' => __('Demo'), 'is_personal' => false, 'access' => 'anyone']);
        $team->forceFill(['is_demo' => true])->save();

        $keeper = self::account(__('Ada (sample data)'), 'sample', $team);
        $team->memberships()->create(['user_id' => $keeper->id, 'role' => TeamRole::Owner]);

        $domain = (string) config('exposehub.tunnel_domain');
        $shop = 'demo-shop.'.$domain;
        $api = 'demo-api.'.$domain;

        // Names another team already saved or connected stay theirs.
        foreach ([$shop, $api] as $hostname) {
            if (! SavedAddress::query()->where('hostname', $hostname)->exists()) {
                $team->savedAddresses()->create(['user_id' => $keeper->id, 'hostname' => $hostname, 'access' => 'default']);
            }
        }
        if (! CustomDomain::query()->where('hostname', 'dev.example.com')->exists()) {
            $team->customDomains()->create([
                'user_id' => $keeper->id,
                'hostname' => 'dev.example.com',
                'verification_token' => Str::random(40),
                'verified_at' => now(),
                'routed_at' => now(),
                'access' => 'default',
            ]);
        }
        $team->accessNetworks()->create([
            'created_by' => $keeper->id,
            'name' => __('Office'),
            'entries' => [['cidr' => '198.51.100.0/24', 'note' => __('An example range')]],
        ]);

        // A token nobody holds: its secret is thrown away here.
        $token = AgentToken::query()->create([
            'team_id' => $team->id,
            'user_id' => $keeper->id,
            'name' => 'ada-laptop',
            'prefix' => AgentToken::PREFIX.Str::lower(Str::random(6)),
            'hash' => AgentToken::hashOf(Str::random(64)),
        ]);

        RequestLog::query()->insert(self::sampleRequests($team, $keeper, $token, $shop, $api));
    }

    /**
     * An account of the demo. Nobody can sign in to it with a password, and
     * its address cannot receive mail.
     */
    private static function account(string $name, string $mailbox, Team $team): User
    {
        $account = new User;
        $account->forceFill([
            'name' => $name,
            'email' => $mailbox.'@'.self::MAIL_DOMAIN,
            'password' => Str::random(48),
            'email_verified_at' => now(),
            'current_team_id' => $team->id,
            'is_demo' => true,
        ])->save();

        return $account;
    }

    /**
     * The last twenty minutes of a small shop in development: page loads,
     * a login, two payment webhooks of which one failed, and a few strangers
     * probing for files.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sampleRequests(Team $team, User $keeper, AgentToken $token, string $shop, string $api): array
    {
        $requests = [
            [1190, $shop, 'GET', '/', 200, 41.2, 0, 18422],
            [1189, $shop, 'GET', '/assets/app.css', 200, 6.4, 0, 48211],
            [1189, $shop, 'GET', '/assets/app.js', 200, 9.8, 0, 131004],
            [1120, $shop, 'GET', '/products', 200, 63.0, 0, 24105],
            [1101, $shop, 'GET', '/products/espresso-cups', 200, 58.7, 0, 15310],
            [1064, $shop, 'POST', '/cart', 302, 22.9, 148, 0],
            [1063, $shop, 'GET', '/cart', 200, 35.1, 0, 9120],
            [980, $shop, 'GET', '/login', 200, 19.4, 0, 6044],
            [961, $shop, 'POST', '/login', 302, 212.6, 96, 0],
            [960, $shop, 'GET', '/account', 200, 44.0, 0, 11873],
            [870, $api, 'POST', '/webhooks/payment?sig=[redacted]', 200, 38.2, 612, 2],
            [842, $api, 'GET', '/v1/orders?page=1', 200, 27.5, 0, 4310],
            [790, $shop, 'GET', '/.env', 404, 3.1, 0, 153],
            [789, $shop, 'GET', '/wp-login.php', 404, 2.8, 0, 153],
            [731, $api, 'GET', '/v1/orders/1042', 200, 18.9, 0, 1284],
            [655, $shop, 'GET', '/checkout', 200, 71.3, 0, 13902],
            [640, $shop, 'POST', '/checkout', 302, 304.8, 420, 0],
            [612, $api, 'POST', '/webhooks/payment?sig=[redacted]', 500, 1204.5, 598, 71],
            [488, $api, 'POST', '/webhooks/payment?sig=[redacted]', 200, 41.7, 598, 2],
            [431, $api, 'GET', '/v1/orders/1043', 200, 16.2, 0, 1301],
            [377, $shop, 'GET', '/orders/1043', 200, 52.4, 0, 10266],
            [250, $shop, 'GET', '/products?q=grinder', 200, 88.1, 0, 20417],
            [190, $shop, 'GET', '/products/hand-grinder', 200, 60.3, 0, 16008],
            [121, $api, 'GET', '/health', 200, 1.9, 0, 15],
            [64, $shop, 'GET', '/', 200, 39.6, 0, 18422],
        ];

        return array_map(fn (array $r) => [
            'team_id' => $team->id,
            'user_id' => $keeper->id,
            'agent_token_id' => $token->id,
            'host' => $r[1],
            'method' => $r[2],
            'path' => $r[3],
            'status' => $r[4],
            'duration_ms' => $r[5],
            'request_bytes' => $r[6],
            'response_bytes' => $r[7],
            'remote' => str_contains($r[3], '.env') || str_contains($r[3], 'wp-login') ? '192.0.2.0' : '203.0.113.0',
            'started_at' => now()->subSeconds($r[0]),
        ], $requests);
    }
}
