<?php

namespace Tests\Feature\Security;

use App\Actions\Account\DeleteAccount;
use App\Actions\Admin\AdminActions;
use App\Enums\TeamRole;
use App\Models\CustomDomain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Deleting an account deletes every team nobody else is in, whatever the
 * person's role there, so no team is left without members while its
 * domains and saved names stay taken.
 */
class AdminDataAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exposehub.node_secret' => self::SECRET,
            'exposehub.admin_emails' => ['owner@example.com'],
            'exposehub.tunnel_domain' => 'tunnels.example',
        ]);
        Notification::fake();
    }

    /**
     * A team whose only member is $user in $role, with a connected domain and a saved address.
     */
    private function soleTeam(User $user, TeamRole $role, string $name): Team
    {
        $team = Team::factory()->create(['name' => ucfirst($name)]);
        $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);
        CustomDomain::factory()->forMember($user, $team)->connected()->create(['hostname' => "{$name}.example.org"]);
        $team->savedAddresses()->create(['hostname' => "{$name}.tunnels.example"]);

        return $team;
    }

    private function assertGone(Team $team, string $name): void
    {
        $this->assertNull(Team::withTrashed()->find($team->id));
        $this->assertDatabaseMissing('custom_domains', ['team_id' => $team->id]);
        $this->assertDatabaseMissing('saved_addresses', ['team_id' => $team->id]);

        $snapshot = $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk();
        $this->assertNotContains("{$name}.example.org", array_column($snapshot->json('domains'), 'hostname'));
        $this->assertNotContains("{$name}.tunnels.example", array_column($snapshot->json('reserved'), 'hostname'));
    }

    public function test_a_sole_member_who_is_not_an_owner_takes_the_team_with_their_account(): void
    {
        $user = User::factory()->create();
        $team = $this->soleTeam($user, TeamRole::Member, 'kept');

        app(DeleteAccount::class)->handle($user);

        $this->assertModelMissing($user);
        $this->assertGone($team, 'kept');
    }

    public function test_an_admin_deleting_the_account_takes_the_team_too(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $user = User::factory()->create();
        $team = $this->soleTeam($user, TeamRole::Admin, 'other');

        app(AdminActions::class)->delete($owner, $user);

        $this->assertModelMissing($user);
        $this->assertGone($team, 'other');
    }

    public function test_teams_with_other_members_are_only_left(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();
        $team = Team::factory()->create();
        $team->memberships()->create(['user_id' => $colleague->id, 'role' => TeamRole::Owner]);
        $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

        app(DeleteAccount::class)->handle($user);

        $this->assertModelMissing($user);
        $this->assertNotSoftDeleted($team);
        $this->assertSame([$colleague->id], $team->memberships()->pluck('user_id')->all());
    }

    public function test_the_owner_check_is_made_again_when_deleting(): void
    {
        // Someone joined after the caller's check: deleting the team now would
        // take it from them.
        $user = User::factory()->create();
        $team = $this->soleTeam($user, TeamRole::Owner, 'shared');
        $team->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => TeamRole::Member]);

        try {
            app(DeleteAccount::class)->handle($user);
            $this->fail('The account was deleted under a team other people are in.');
        } catch (AuthorizationException) {
            //
        }

        $this->assertModelExists($user);
        $this->assertModelExists($team);
        $this->assertSame(2, $team->memberships()->count());
    }
}
