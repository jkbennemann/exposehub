<?php

namespace Tests\Feature\Security;

use App\Enums\TeamRole;
use App\Models\RequestLog;
use App\Models\SavedAddress;
use App\Models\Team;
use App\Models\User;
use App\Support\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What one account can make the dashboard store, look up or load is bounded.
 */
class ResourceLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exposehub.tunnel_domain' => 'tunnels.example',
            'exposehub.agent_server' => 'https://connect.tunnels.example',
            'exposehub.custom_domain_target' => 'connect.tunnels.example',
        ]);
    }

    private function ownerOfNewTeam(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $team = Team::factory()->create();
        $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_one_person_saves_a_limited_number_of_addresses_across_teams(): void
    {
        config(['exposehub.limits.saved_addresses_per_user' => 3]);
        $user = $this->ownerOfNewTeam();
        foreach (['one', 'two', 'three'] as $name) {
            Livewire::actingAs($user)->test('pages::access.index')->set('name', $name)->call('saveAddress')->assertHasNoErrors();
        }

        // A new team does not bring a fresh allowance.
        $this->ownerOfNewTeam($user);
        Livewire::actingAs($user)->test('pages::access.index')->set('name', 'four')->call('saveAddress')->assertHasErrors('name');

        $this->assertSame(3, SavedAddress::where('user_id', $user->id)->count());
    }

    public function test_a_team_has_a_limited_number_of_domains(): void
    {
        config(['exposehub.limits.domains_per_team' => 2]);
        $user = $this->ownerOfNewTeam();
        foreach (['a.shop.example', 'b.shop.example'] as $hostname) {
            Livewire::actingAs($user)->test('pages::domains.index')->set('hostname', $hostname)->call('addDomain')->assertHasNoErrors();
        }

        Livewire::actingAs($user)->test('pages::domains.index')->set('hostname', 'c.shop.example')->call('addDomain')->assertHasErrors('hostname');

        $this->assertSame(2, $user->currentTeam->customDomains()->count());
    }

    public function test_the_domains_page_does_not_look_up_dns_on_every_request(): void
    {
        $resolver = new class implements Resolver
        {
            public int $lookups = 0;

            public function txt(string $name): array
            {
                return [];
            }

            public function addresses(string $name): array
            {
                return ['203.0.113.7'];
            }

            public function isZoneApex(string $name): bool
            {
                $this->lookups++;

                return false;
            }
        };
        $this->app->instance(Resolver::class, $resolver);
        $user = $this->ownerOfNewTeam();
        foreach (['a.shop.example', 'b.shop.example', 'c.shop.example'] as $hostname) {
            $user->currentTeam->customDomains()->create(['user_id' => $user->id, 'hostname' => $hostname, 'verification_token' => 'token']);
        }

        $page = Livewire::actingAs($user)->test('pages::domains.index')->assertSee('connect.tunnels.example');
        $this->assertSame(3, $resolver->lookups);

        $page->set('hostname', 'typing')->set('hostname', 'still-typing');
        $this->assertSame(3, $resolver->lookups);
    }

    public function test_the_logs_page_loads_a_bounded_number_of_rows_and_bodies_only_for_the_open_one(): void
    {
        $user = $this->ownerOfNewTeam();
        $team = $user->currentTeam;
        $logs = RequestLog::factory()->forMember($user, $team)->count(3)->create([
            'started_at' => now()->subMinute(),
            'details' => ['request_header' => ['X-Secret' => ['big']], 'request_body' => base64_encode('BODY-TEXT')],
        ]);

        $page = Livewire::actingAs($user)->test('pages::logs.index');

        try {
            $page->set('limit', 100000);
            $this->fail('The page size could be set by the client.');
        } catch (CannotUpdateLockedPropertyException) {
            // Expected: only "Show older" grows the list.
        }

        $page = Livewire::actingAs($user)->test('pages::logs.index');
        for ($i = 0; $i < 20; $i++) {
            $page->call('showOlder');
        }
        $this->assertSame(500, $page->get('limit'));

        $this->assertTrue($page->instance()->rows->every(fn (RequestLog $log) => ! array_key_exists('details', $log->getAttributes())));

        $page->call('toggle', $logs->first()->id)->assertSee('X-Secret')->assertSee('BODY-TEXT');
    }

    public function test_a_limit_set_to_an_empty_value_keeps_its_default_and_zero_means_none(): void
    {
        $limits = function (string $value): array {
            $_ENV['EXPOSEHUB_MAX_TEAMS_PER_USER'] = $_SERVER['EXPOSEHUB_MAX_TEAMS_PER_USER'] = $value;
            putenv("EXPOSEHUB_MAX_TEAMS_PER_USER={$value}");

            return (require config_path('exposehub.php'))['limits'];
        };

        try {
            // Compose passes a variable left out of .env as an empty string.
            $this->assertSame(10, $limits('')['teams_per_user']);
            $this->assertSame(0, $limits('0')['teams_per_user']);
            $this->assertSame(3, $limits('3')['teams_per_user']);
        } finally {
            unset($_ENV['EXPOSEHUB_MAX_TEAMS_PER_USER'], $_SERVER['EXPOSEHUB_MAX_TEAMS_PER_USER']);
            putenv('EXPOSEHUB_MAX_TEAMS_PER_USER');
        }
    }

    public function test_the_guessing_limits_cannot_be_set_so_low_that_nobody_gets_in(): void
    {
        $names = [
            'EXPOSEHUB_MAX_PASSWORD_CHECKS_PER_MINUTE' => 'password_checks_per_minute',
            'EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_MINUTE' => 'two_factor_attempts_per_minute',
            'EXPOSEHUB_MAX_TWO_FACTOR_ATTEMPTS_PER_DAY' => 'two_factor_attempts_per_day',
            'EXPOSEHUB_MAX_TWO_FACTOR_FAILURES_IN_A_ROW' => 'two_factor_failures_in_a_row',
        ];

        try {
            foreach (array_keys($names) as $env) {
                $_ENV[$env] = $_SERVER[$env] = '0';
                putenv("{$env}=0");
            }

            $limits = (require config_path('exposehub.php'))['limits'];

            foreach ($names as $key) {
                $this->assertSame(1, $limits[$key], $key);
            }
        } finally {
            foreach (array_keys($names) as $env) {
                unset($_ENV[$env], $_SERVER[$env]);
                putenv($env);
            }
        }
    }
}
