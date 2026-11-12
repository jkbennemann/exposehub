<?php

namespace Tests\Feature\Security;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Someone removed from a team may not keep working on its pages through a
 * page that is still open or a snapshot they saved while still a member.
 */
class StaleTeamSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use ReplaysLivewireSnapshots;

    private const array PAGES = [
        'access.index' => 'pages::access.index',
        'domains.index' => 'pages::domains.index',
        'tokens.index' => 'pages::tokens.index',
        'logs.index' => 'pages::logs.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exposehub.tunnel_domain' => 'tunnels.example',
            'exposehub.agent_server' => 'https://connect.tunnels.example',
        ]);
        Notification::fake();
    }

    /**
     * @return array{Team, User}
     */
    private function teamWithMember(TeamRole $role = TeamRole::Member): array
    {
        $team = Team::factory()->create(['name' => 'Shop crew']);
        $team->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => TeamRole::Owner]);
        $member = User::factory()->create();
        $team->memberships()->create(['user_id' => $member->id, 'role' => $role]);
        $member->switchTeam($team);

        return [$team, $member];
    }

    private function remove(Team $team, User $member): void
    {
        $team->memberships()->where('user_id', $member->id)->delete();
        $member->switchTeam($member->personalTeam());
    }

    public function test_a_removed_member_cannot_replay_a_snapshot_of_any_team_page(): void
    {
        [$team, $member] = $this->teamWithMember();
        $this->actingAs($member);

        $snapshots = [];
        foreach (self::PAGES as $route => $component) {
            $html = $this->get(route($route, ['current_team' => $team->slug]))->assertOk()->getContent();
            $snapshots[$component] = $this->snapshotOf($html, $component);
            $this->replay($snapshots[$component])->assertOk();
        }

        $this->remove($team, $member);

        foreach ($snapshots as $component => $snapshot) {
            $response = $this->replay($snapshot);
            $response->assertForbidden();
            $this->assertStringNotContainsString('Shop crew', $response->getContent(), $component);
        }

        $this->replay($snapshots['pages::access.index'], ['name' => 'stale'], [['saveAddress']])->assertForbidden();
        $this->assertSame(0, $team->savedAddresses()->count());
    }

    public function test_livewire_reapplies_the_membership_middleware_to_components_without_their_own_check(): void
    {
        // The team switcher in the header checks no team of its own, so only
        // the re-applied route middleware can refuse it here.
        [$team, $member] = $this->teamWithMember();
        $html = $this->actingAs($member)->get(route('access.index', ['current_team' => $team->slug]))->assertOk()->getContent();
        $switcher = $this->snapshotOf($html, 'team-switcher');

        $this->replay($switcher)->assertOk();

        $this->remove($team, $member);

        $this->replay($switcher)->assertForbidden();
    }

    public function test_every_team_page_component_checks_membership_itself(): void
    {
        // Livewire::test() skips the route and Livewire's persistent middleware,
        // so this checks the components' own guard.
        [$team, $member] = $this->teamWithMember(TeamRole::Admin);

        $components = [];
        foreach (self::PAGES as $component) {
            $components[$component] = Livewire::actingAs($member)->test($component);
        }

        $this->remove($team, $member);

        $components['pages::access.index']->set('name', 'stale')->assertForbidden();
        $components['pages::domains.index']->call('addDomain')->assertForbidden();
        $components['pages::tokens.index']->call('createToken')->assertForbidden();
        $components['pages::logs.index']->call('setRange', '7d')->assertForbidden();

        $this->assertSame(0, $team->savedAddresses()->count());
        $this->assertSame(0, $team->customDomains()->count());
    }

    public function test_a_livewire_request_does_not_switch_the_current_team(): void
    {
        [$team, $member] = $this->teamWithMember();
        $html = $this->actingAs($member)->get(route('access.index', ['current_team' => $team->slug]))->assertOk()->getContent();
        $snapshot = $this->snapshotOf($html, 'pages::access.index');

        $member->switchTeam($member->personalTeam());

        $this->replay($snapshot)->assertOk();
        $this->assertSame($member->personalTeam()->id, $member->fresh()->current_team_id);
    }
}
