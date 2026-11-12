<?php

namespace Tests\Feature\Tunnels;

use App\Enums\TeamRole;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\TunnelDisconnect;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\ServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class TunnelsPageTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.node_secret' => self::SECRET]);
    }

    /**
     * A team with an owner and one plain member.
     *
     * @return array{Team, User, User}
     */
    private function teamWithMember(): array
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $member = User::factory()->create();
        $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
        $member->switchTeam($team);

        return [$team, $owner, $member];
    }

    /**
     * One agent's session as the tunnel server reports it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agentSession(AgentToken $token, string $host, array $overrides = []): array
    {
        return $overrides + [
            'id' => md5($host), 'token_id' => (string) $token->id, 'account' => $token->team->slug,
            'hosts' => [$host], 'protected' => [], 'restricted' => [],
            'agent_version' => '0.1.0', 'connected_at' => now()->subHours(3)->toIso8601String(),
        ];
    }

    /**
     * Has the tunnel server report these sessions, through its real endpoint.
     *
     * @param  array<int, array<string, mixed>>  $sessions
     */
    private function report(array $sessions, ?string $agentRelease = '0.1.0'): void
    {
        $this->withToken(self::SECRET)->postJson(route('internal.status'), [
            'server_version' => '0.1.0', 'agent_release' => $agentRelease,
            'started_at' => now()->subDay()->toIso8601String(), 'request_logs' => true,
            'tunnels' => $sessions,
        ])->assertNoContent();
    }

    /**
     * What the tunnel server is asked to close with its next snapshot.
     *
     * @return array<int, array{session: string, token_id: int}>
     */
    private function disconnectsInSnapshot(): array
    {
        return $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk()->json('disconnects');
    }

    public function test_the_page_says_so_when_no_tunnel_is_open(): void
    {
        $user = User::factory()->create();
        $this->report([]);

        $this->actingAs($user)
            ->get(route('tunnels.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSee('No tunnel is open')
            ->assertSee('exposehub http 8000');
    }

    public function test_the_page_of_another_team_is_forbidden(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->get(route('tunnels.index', ['current_team' => $other->currentTeam->slug]))
            ->assertForbidden();
    }

    public function test_without_a_report_the_page_does_not_claim_that_nothing_is_open(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        Livewire::test('pages::tunnels.index')
            ->assertSee('The tunnel server is not reporting')
            ->assertDontSee('No tunnel is open');
    }

    public function test_an_old_report_is_shown_with_a_warning(): void
    {
        $user = User::factory()->create();
        $token = AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        $this->report([$this->agentSession($token, 'shop.tunnel.test')]);

        $this->travel(ServiceStatus::STALE_AFTER + 30)->seconds();

        $this->actingAs($user);
        Livewire::test('pages::tunnels.index')
            ->assertSee('shop.tunnel.test')
            ->assertSee('This list may be out of date');
    }

    public function test_members_see_only_their_own_tunnels_and_managers_see_the_teams(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        $stranger = User::factory()->create();
        $this->report([
            $this->agentSession(AgentToken::factory()->forMember($owner, $team)->create(['name' => 'Owner desktop']), 'owner.tunnel.test'),
            $this->agentSession(AgentToken::factory()->forMember($member, $team)->create(['name' => 'Member laptop']), 'member.tunnel.test', ['protected' => ['member.tunnel.test']]),
            $this->agentSession(AgentToken::factory()->forMember($stranger, $stranger->currentTeam)->create(), 'stranger.tunnel.test'),
            ['token_id' => null, 'account' => 'static', 'hosts' => ['static.tunnel.test'], 'protected' => [], 'restricted' => [], 'agent_version' => '0.1.0', 'connected_at' => now()->toIso8601String()],
        ]);

        $this->actingAs($member);
        Livewire::test('pages::tunnels.index')
            ->assertSee(['member.tunnel.test', 'Member laptop', 'password', 'open for 3 hours'])
            ->assertDontSee(['owner.tunnel.test', 'stranger.tunnel.test', 'static.tunnel.test']);

        $this->actingAs($owner);
        Livewire::test('pages::tunnels.index')
            ->assertSee(['member.tunnel.test', 'owner.tunnel.test', $member->name])
            ->assertDontSee(['stranger.tunnel.test', 'static.tunnel.test']);
    }

    public function test_a_member_disconnects_their_own_tunnel_and_the_token_keeps_working(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        $other = AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        $this->report([
            $session = $this->agentSession($token, 'shop.tunnel.test'),
            $this->agentSession($other, 'api.tunnel.test'),
        ]);

        $this->actingAs($user);
        $page = Livewire::test('pages::tunnels.index')
            ->call('disconnect', $session['id'])
            ->assertHasNoErrors()
            ->assertSee('Disconnecting');

        $this->assertSame([['session' => $session['id'], 'token_id' => $token->id]], $this->disconnectsInSnapshot());
        $this->assertNull($token->fresh()->revoked_at);
        Notification::assertNothingSent();

        // Asked twice, the server still hears of it once.
        $page->call('disconnect', $session['id']);
        $this->assertCount(1, $this->disconnectsInSnapshot());
    }

    public function test_a_member_cannot_disconnect_a_colleagues_tunnel(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        $token = AgentToken::factory()->forMember($owner, $team)->create();
        $this->report([$session = $this->agentSession($token, 'owner.tunnel.test')]);

        $this->actingAs($member);
        Livewire::test('pages::tunnels.index')
            ->call('disconnect', $session['id'])
            ->assertNotFound();

        $this->assertSame([], $this->disconnectsInSnapshot());
    }

    public function test_an_owner_disconnects_a_members_tunnel_and_the_member_gets_one_mail(): void
    {
        Notification::fake();
        [$team, $owner, $member] = $this->teamWithMember();
        $token = AgentToken::factory()->forMember($member, $team)->create();
        $this->report([$session = $this->agentSession($token, 'member.tunnel.test')]);

        $this->actingAs($owner);
        Livewire::test('pages::tunnels.index')
            ->call('disconnect', $session['id'])
            ->call('disconnect', $session['id']);

        $this->assertSame([['session' => $session['id'], 'token_id' => $token->id]], $this->disconnectsInSnapshot());
        Notification::assertSentToTimes($member, AccountNotice::class, 1);
        Notification::assertSentTo($member, AccountNotice::class, function (AccountNotice $notice) use ($member, $owner) {
            $mail = $notice->toMail($member)->render();

            return $notice->kind === 'tunnel-disconnected'
                && str_contains($mail, 'member.tunnel.test')
                && str_contains($mail, e($owner->name));
        });
    }

    public function test_a_tunnel_of_another_team_cannot_be_disconnected(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $token = AgentToken::factory()->forMember($stranger, $stranger->currentTeam)->create();
        $this->report([$session = $this->agentSession($token, 'stranger.tunnel.test')]);

        $this->actingAs($user);
        Livewire::test('pages::tunnels.index')
            ->call('disconnect', $session['id'])
            ->assertNotFound();
        Livewire::test('pages::tunnels.index')
            ->call('disconnect', 'no-such-session')
            ->assertNotFound();

        $this->assertSame(0, TunnelDisconnect::count());
    }

    public function test_someone_removed_from_the_team_cannot_disconnect_through_a_page_left_open(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        $token = AgentToken::factory()->forMember($member, $team)->create();
        $this->report([$session = $this->agentSession($token, 'member.tunnel.test')]);

        $this->actingAs($member);
        $page = Livewire::test('pages::tunnels.index');
        $team->memberships()->where('user_id', $member->id)->delete();

        $page->call('disconnect', $session['id'])->assertForbidden();
        $this->assertSame(0, TunnelDisconnect::count());
    }

    public function test_tunnels_left_open_in_other_teams_are_pointed_out(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        $home = $member->teams()->where('teams.id', '!=', $team->id)->firstOrFail();
        $this->report([
            $this->agentSession(AgentToken::factory()->forMember($member, $home)->create(), 'home.tunnel.test'),
            $this->agentSession(AgentToken::factory()->forMember($owner, $team)->create(), 'owner.tunnel.test'),
        ]);

        $this->actingAs($member);
        Livewire::test('pages::tunnels.index')
            ->assertSee('You also have tunnels open in')
            ->assertSee($home->name)
            ->assertSee(route('tunnels.index', ['current_team' => $home->slug]))
            ->assertDontSee('home.tunnel.test');

        $this->actingAs($owner);
        Livewire::test('pages::tunnels.index')->assertDontSee('You also have tunnels open in');
    }

    public function test_an_outdated_agent_is_told_how_to_update(): void
    {
        $user = User::factory()->create();
        $token = AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        $this->report([$this->agentSession($token, 'shop.tunnel.test', ['agent_version' => '0.0.9'])]);

        $this->actingAs($user);
        Livewire::test('pages::tunnels.index')->assertSee(['agent 0.0.9', 'exposehub update']);
    }

    public function test_a_session_an_older_server_reports_without_an_id_is_listed_but_cannot_be_disconnected(): void
    {
        $user = User::factory()->create();
        $token = AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        $session = $this->agentSession($token, 'shop.tunnel.test');
        unset($session['id']);
        $this->report([$session]);

        $this->actingAs($user);
        Livewire::test('pages::tunnels.index')
            ->assertSee('shop.tunnel.test')
            ->assertDontSeeHtml('data-test="tunnel-disconnect-button"')
            ->call('disconnect', '')
            ->assertNotFound();
    }

    public function test_the_server_stops_hearing_of_a_request_after_some_minutes_and_it_is_deleted(): void
    {
        $user = User::factory()->create();
        $token = AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        $this->report([$session = $this->agentSession($token, 'shop.tunnel.test')]);

        $this->actingAs($user);
        Livewire::test('pages::tunnels.index')->call('disconnect', $session['id']);
        $this->assertCount(1, $this->disconnectsInSnapshot());

        $this->travel(TunnelDisconnect::OPEN_MINUTES + 1)->minutes();

        $this->assertSame([], $this->disconnectsInSnapshot());
        $this->assertSame(1, TunnelDisconnect::query()->expired()->delete());
    }

    public function test_the_main_navigation_links_to_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSee(route('tunnels.index', ['current_team' => $user->currentTeam->slug]));
    }
}
