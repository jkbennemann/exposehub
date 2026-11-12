<?php

namespace Tests\Feature\Internal;

use App\Models\User;
use App\Support\ServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StatusReportTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.admin_emails' => ['owner@example.com'], 'exposehub.node_secret' => self::SECRET]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tunnel(string $account, array $overrides = []): array
    {
        return $overrides + [
            'token_id' => null, 'account' => $account, 'hosts' => ["{$account}.tunnel.test"], 'protected' => [], 'restricted' => [],
            'agent_version' => '0.7.0', 'connected_at' => now()->subMinute()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, mixed>  $tunnels
     */
    private function report(array $tunnels): void
    {
        $this->withToken(self::SECRET)->postJson(route('internal.status'), [
            'server_version' => '0.7.0', 'started_at' => now()->subHour()->toIso8601String(), 'request_logs' => true,
            'tunnels' => $tunnels,
        ])->assertNoContent();
    }

    public function test_one_agents_over_long_version_does_not_cost_the_whole_report(): void
    {
        $this->report([
            $this->tunnel('victim'),
            $this->tunnel('acme', ['agent_version' => str_repeat('9', 65)]),
            $this->tunnel('wide', ['agent_version' => str_repeat('é', 200)]),
        ]);

        $tunnels = ServiceStatus::report()['tunnels'] ?? [];
        $this->assertSame(['victim', 'acme', 'wide'], array_column($tunnels, 'account'));
        $this->assertSame(str_repeat('9', 64), $tunnels[1]['agent_version']);
        $this->assertSame(str_repeat('é', 64), $tunnels[2]['agent_version']);

        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.tunnels'))->assertOk()->assertSee('victim.tunnel.test')->assertSee('acme.tunnel.test');
    }

    public function test_a_tunnel_that_does_not_fit_is_left_out_and_logged_and_the_rest_is_kept(): void
    {
        Log::spy();

        $this->report([
            $this->tunnel('victim'),
            $this->tunnel('broken', ['hosts' => array_fill(0, 17, 'x.tunnel.test')]),
            'not a tunnel',
            $this->tunnel('other', ['agent_version' => null]),
        ]);

        $tunnels = ServiceStatus::report()['tunnels'] ?? [];
        $this->assertSame(['victim', 'other'], array_column($tunnels, 'account'));
        $this->assertNull($tunnels[1]['agent_version']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_server_says_when_its_token_list_is_stale_and_since_when(): void
    {
        $this->withToken(self::SECRET)->postJson(route('internal.status'), [
            'server_version' => '0.7.0', 'started_at' => now()->subHour()->toIso8601String(), 'request_logs' => true,
            'tokens_stale' => true, 'tokens_refreshed_at' => '2026-11-12T09:15:30.123456789Z', 'tunnels' => [],
        ])->assertNoContent();

        $this->assertTrue(ServiceStatus::tokensStale());
        $this->assertSame('2026-11-12T09:15:30+00:00', ServiceStatus::tokensRefreshedAt()?->toIso8601String());
    }

    public function test_a_report_from_a_server_that_does_not_know_about_stale_tokens_is_still_accepted(): void
    {
        $this->report([$this->tunnel('acme')]);

        $this->assertSame(['acme'], array_column(ServiceStatus::report()['tunnels'] ?? [], 'account'));
        $this->assertFalse(ServiceStatus::tokensStale());
        $this->assertNull(ServiceStatus::tokensRefreshedAt());
    }

    public function test_token_staleness_that_is_not_a_flag_and_a_time_is_refused(): void
    {
        foreach ([['tokens_stale' => 'maybe'], ['tokens_refreshed_at' => 'not a time']] as $wrong) {
            $this->withToken(self::SECRET)->postJson(route('internal.status'), $wrong + [
                'server_version' => '0.7.0', 'started_at' => now()->toIso8601String(), 'request_logs' => true, 'tunnels' => [],
            ])->assertUnprocessable();
        }

        $this->assertNull(ServiceStatus::report());
    }

    public function test_a_report_that_is_wrong_as_a_whole_is_still_refused(): void
    {
        $this->withToken(self::SECRET)->postJson(route('internal.status'), [
            'server_version' => str_repeat('9', 65), 'started_at' => now()->toIso8601String(), 'request_logs' => true, 'tunnels' => [],
        ])->assertUnprocessable();

        $this->assertNull(ServiceStatus::report());
    }
}
