<?php

namespace Tests\Feature\Security;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who joins a team, with which role, and that a team keeps its owner.
 */
class TeamRolesAndInvitationsTest extends TestCase
{
    use RefreshDatabase;
    use ReplaysLivewireSnapshots;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * @return array{Team, User, User}
     */
    private function teamWithAdmin(): array
    {
        $team = Team::factory()->create();
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

        return [$team, $owner, $admin];
    }

    public function test_nobody_invites_with_the_owner_role(): void
    {
        [$team, $owner, $admin] = $this->teamWithAdmin();

        foreach ([$admin, $owner] as $inviter) {
            Livewire::actingAs($inviter)->test('pages::teams.invite-member-modal', ['team' => $team])
                ->set('inviteEmail', 'taker@example.com')
                ->set('inviteRole', TeamRole::Owner->value)
                ->call('createInvitation')
                ->assertHasErrors('inviteRole');
        }

        $this->assertSame(0, $team->invitations()->count());
    }

    public function test_an_invitation_with_the_owner_role_is_not_honoured(): void
    {
        [$team, , $admin] = $this->teamWithAdmin();
        $taker = User::factory()->create(['email' => 'taker@example.com']);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id, 'email' => $taker->email, 'role' => TeamRole::Owner, 'invited_by' => $admin->id,
        ]);

        Livewire::actingAs($taker)->test('pages::teams.pending-invitations-modal')
            ->call('acceptInvitation', $invitation->code)
            ->assertForbidden();

        $this->assertFalse($taker->belongsToTeam($team));
    }

    public function test_the_owner_cannot_be_removed_or_demoted_and_nobody_is_made_owner(): void
    {
        [$team, $owner] = $this->teamWithAdmin();
        $secondOwner = User::factory()->create();
        $team->members()->attach($secondOwner, ['role' => TeamRole::Owner->value]);
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        Livewire::actingAs($secondOwner)->test('pages::teams.remove-member-modal', ['team' => $team])
            ->set('memberId', $owner->id)
            ->call('removeMember')
            ->assertForbidden();

        Livewire::actingAs($secondOwner)->test('pages::teams.edit', ['team' => $team])
            ->call('updateMember', $owner->id, TeamRole::Member->value)
            ->assertForbidden();

        Livewire::actingAs($secondOwner)->test('pages::teams.edit', ['team' => $team])
            ->call('updateMember', $member->id, TeamRole::Owner->value)
            ->assertHasErrors('role');

        $this->assertSame(TeamRole::Owner, $owner->teamRole($team));
        $this->assertSame(TeamRole::Member, $member->teamRole($team));
    }

    public function test_remove_member_only_removes_members_of_this_team(): void
    {
        [$team, $owner] = $this->teamWithAdmin();
        $otherTeam = Team::factory()->create();
        $stranger = User::factory()->create();
        $otherTeam->members()->attach($stranger, ['role' => TeamRole::Member->value]);

        Livewire::actingAs($owner)->test('pages::teams.remove-member-modal', ['team' => $team])
            ->set('memberId', $stranger->id)
            ->call('removeMember')
            ->assertNotFound();

        $this->assertTrue($stranger->belongsToTeam($otherTeam));
    }

    public function test_an_unverified_account_neither_sees_nor_accepts_invitations_to_its_address(): void
    {
        [$team, , $admin] = $this->teamWithAdmin();
        $squatter = User::factory()->unverified()->create(['email' => 'invited@example.com']);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id, 'email' => 'invited@example.com', 'role' => TeamRole::Member, 'invited_by' => $admin->id,
        ]);

        Livewire::actingAs($squatter)->test('pages::teams.pending-invitations-modal')
            ->assertDontSee($invitation->code)
            ->call('acceptInvitation', $invitation->code)
            ->assertForbidden();

        $this->assertFalse($squatter->belongsToTeam($team));
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_an_unverified_account_cannot_replay_a_dashboard_snapshot_to_accept(): void
    {
        [$team, , $admin] = $this->teamWithAdmin();
        $user = User::factory()->create(['email' => 'invited@example.com']);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id, 'email' => 'invited@example.com', 'role' => TeamRole::Member, 'invited_by' => $admin->id,
        ]);
        $html = $this->actingAs($user)->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))->assertOk()->getContent();
        $snapshot = $this->snapshotOf($html, 'pages::teams.pending-invitations-modal');

        $user->forceFill(['email_verified_at' => null])->save();

        $this->assertContains($this->replay($snapshot, [], [['acceptInvitation', [$invitation->code]]])->status(), [302, 403]);
        $this->assertFalse($user->belongsToTeam($team));
    }

    public function test_one_person_owns_a_limited_number_of_teams(): void
    {
        config(['exposehub.limits.teams_per_user' => 3]);
        $user = User::factory()->create();
        for ($i = $user->ownedTeams()->count(); $i < 3; $i++) {
            Livewire::actingAs($user)->test('pages::teams.index')->set('name', "Team {$i}")->call('createTeam')->assertHasNoErrors();
        }
        $this->assertSame(3, $user->ownedTeams()->count());

        Livewire::actingAs($user)->test('pages::teams.index')->set('name', 'One too many')->call('createTeam')->assertForbidden();
        Livewire::actingAs($user)->test('create-team-modal')->set('teamName', 'One too many')->call('createTeam')->assertForbidden();

        $this->assertSame(3, $user->ownedTeams()->count());
    }

    public function test_the_last_owner_cannot_give_up_the_owner_role(): void
    {
        [$team, $owner] = $this->teamWithAdmin();

        foreach ([TeamRole::Admin, TeamRole::Member] as $role) {
            Livewire::actingAs($owner)->test('pages::teams.edit', ['team' => $team])
                ->call('updateMember', $owner->id, $role->value)
                ->assertForbidden();
        }

        $this->assertSame(TeamRole::Owner, $owner->teamRole($team));
    }

    public function test_invitation_mail_is_rate_limited_per_inviter_and_per_address(): void
    {
        [$team, $owner] = $this->teamWithAdmin();
        $invite = fn (string $email) => Livewire::actingAs($owner)->test('pages::teams.invite-member-modal', ['team' => $team])
            ->set('inviteEmail', $email)->set('inviteRole', TeamRole::Member->value)->call('createInvitation');

        // Invite, cancel, invite again: twice a day per address and team.
        $invite('again@example.com')->assertHasNoErrors();
        $team->invitations()->delete();
        $invite('again@example.com')->assertHasNoErrors();
        $team->invitations()->delete();
        $invite('again@example.com')->assertHasErrors('inviteEmail');
        $this->assertSame(0, $team->invitations()->count());

        // Ten an hour per inviter.
        for ($i = 3; $i <= 10; $i++) {
            $invite("person{$i}@example.com")->assertHasNoErrors();
        }
        $invite('eleven@example.com')->assertHasErrors('inviteEmail');

        $this->assertSame(8, $team->invitations()->count());
        Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 10);
    }
}
