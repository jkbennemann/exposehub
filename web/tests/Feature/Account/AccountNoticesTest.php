<?php

namespace Tests\Feature\Account;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Tokens\IssueAgentToken;
use App\Enums\TeamRole;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\Domains\Provisioner;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AccountNoticesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * The notices of one kind sent to a user.
     */
    private function sentTo(User $user, string $kind): int
    {
        return Notification::sent($user, AccountNotice::class)->filter(fn (AccountNotice $n) => $n->kind === $kind)->count();
    }

    /**
     * The notice of one kind sent to a bare address, rendered as mail.
     */
    private function mailedTo(string $address, string $kind): ?string
    {
        $match = null;
        Notification::assertSentOnDemand(AccountNotice::class, function (AccountNotice $notice, array $channels, AnonymousNotifiable $notifiable) use ($address, $kind, &$match) {
            if ($notice->kind === $kind && $notifiable->routes['mail'] === $address) {
                $match = $notice;

                return true;
            }

            return false;
        });

        return $match ? implode("\n", $match->toMail($notifiable ?? new AnonymousNotifiable)->introLines).' '.$match->toMail(new AnonymousNotifiable)->greeting : null;
    }

    /**
     * @return array{Team, User, User}
     */
    private function sharedTeam(): array
    {
        $owner = User::factory()->create(['name' => 'Olivia Owner']);
        $team = Team::factory()->create(['name' => 'Platform']);
        $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
        $member = User::factory()->create(['name' => 'Max Member']);
        $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

        return [$team, $owner, $member];
    }

    // ------------------------------------------------------------ account deletion

    public function test_deleting_an_account_confirms_it_by_mail_to_the_old_address(): void
    {
        [$shared, , $member] = $this->sharedTeam();
        AgentToken::factory()->forMember($member, $member->currentTeam)->create();
        AgentToken::factory()->forMember($member, $shared)->create();
        $address = $member->email;
        $this->actingAs($member);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($member);
        $intro = $this->mailedTo($address, 'account-deleted');
        $this->assertNotNull($intro, 'No confirmation was mailed to the deleted account\'s address.');
        $this->assertStringContainsString('Your 2 agent tokens were revoked', $intro);
        $this->assertStringContainsString('You are no longer a member of: Platform.', $intro);
        $this->assertStringContainsString('Hello Max Member,', $intro);
    }

    public function test_deleting_an_account_deletes_the_personal_team_for_good_and_keeps_shared_ones(): void
    {
        [$shared, $owner, $member] = $this->sharedTeam();
        $personal = $member->currentTeam;
        $domain = CustomDomain::factory()->forMember($member, $shared)->connected()->create();
        $this->actingAs($member);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser');

        $this->assertDatabaseMissing('teams', ['id' => $personal->id]);
        $this->assertNotSoftDeleted($shared);
        $this->assertNull($domain->fresh()->user_id, 'The shared team\'s domain must stay, without its former creator.');
        $this->assertTrue($owner->fresh()->belongsToTeam($shared));
    }

    public function test_an_owner_of_a_team_others_still_use_cannot_delete_the_account(): void
    {
        [, $owner] = $this->sharedTeam();
        $this->actingAs($owner);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertSee('You own the team Platform, which other people still use.');

        $this->assertModelExists($owner);
        Notification::assertNothingSent();
    }

    public function test_deleting_an_account_removes_the_certificate_setup_of_its_teams_domains(): void
    {
        $user = User::factory()->create();
        $domain = CustomDomain::factory()->forMember($user, $user->currentTeam)->connected()->create(['hostname' => 'dev.example.org']);
        $removed = [];
        $this->app->instance(Provisioner::class, new class($removed) implements Provisioner
        {
            public function __construct(public array &$removed) {}

            public function provision(CustomDomain $domain): void {}

            public function remove(CustomDomain $domain): void
            {
                $this->removed[] = $domain->hostname;
            }

            public function issuesCertificates(): bool
            {
                return true;
            }
        });
        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser');

        $this->assertSame(['dev.example.org'], $removed);
        $this->assertModelMissing($domain);
    }

    public function test_a_failing_mail_does_not_stop_the_deletion(): void
    {
        Notification::shouldReceive('route')->andThrow(new RuntimeException('smtp down'));
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'password')->call('deleteUser')->assertRedirect('/');

        $this->assertModelMissing($user);
    }

    // ------------------------------------------------------------ sign-in

    public function test_changing_the_password_is_mailed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'a-new-long-password')
            ->set('password_confirmation', 'a-new-long-password')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertSame(1, $this->sentTo($user, 'password-changed'));
    }

    public function test_resetting_the_password_is_mailed(): void
    {
        $user = User::factory()->create();

        app(ResetUserPassword::class)->reset($user, ['password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password']);
        event(new PasswordReset($user));

        $this->assertSame(1, $this->sentTo($user, 'password-changed'));
    }

    public function test_changing_the_email_warns_the_old_address_and_verifies_the_new_one(): void
    {
        $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@old.example']);
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Ada')
            ->set('email', 'ada@new.example')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $intro = $this->mailedTo('ada@old.example', 'email-changed');
        $this->assertNotNull($intro);
        $this->assertStringContainsString('ad•@new.example', $intro, 'The new address is shown masked.');
        $this->assertStringNotContainsString('ada@new.example', $intro);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_saving_the_profile_without_a_new_email_sends_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')->set('name', 'Ada Lovelace')->set('email', $user->email)->call('updateProfileInformation');

        Notification::assertNothingSent();
    }

    public function test_two_factor_on_and_off_is_mailed_but_an_abandoned_setup_is_not(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorAuthenticationConfirmed($user));
        $this->assertSame(1, $this->sentTo($user, 'two-factor-enabled'));

        // A setup that was started but never confirmed, then cancelled.
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null])->save();
        $this->actingAs($user);
        Livewire::test('pages::settings.security')->call('disable');
        $this->assertSame(0, $this->sentTo($user, 'two-factor-disabled'));

        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        Livewire::test('pages::settings.security')->call('disable');
        $this->assertSame(1, $this->sentTo($user, 'two-factor-disabled'));
    }

    public function test_adding_and_removing_a_passkey_is_mailed(): void
    {
        $user = User::factory()->create();
        $passkey = new Passkey(['name' => 'Work laptop']);

        event(new PasskeyRegistered($user, $passkey));
        event(new PasskeyDeleted($user, $passkey));

        $this->assertSame(1, $this->sentTo($user, 'passkey-added'));
        $this->assertSame(1, $this->sentTo($user, 'passkey-removed'));
    }

    // ------------------------------------------------------------ tokens and teams

    public function test_a_new_agent_token_is_mailed_to_its_owner(): void
    {
        $user = User::factory()->create();

        app(IssueAgentToken::class)->handle($user, $user->currentTeam, 'Work laptop');

        $this->assertSame(1, $this->sentTo($user, 'agent-token-created'));
    }

    public function test_a_token_revoked_by_someone_else_is_mailed_but_ones_own_revocation_is_not(): void
    {
        [$team, $owner, $member] = $this->sharedTeam();
        $owner->switchTeam($team);
        $member->switchTeam($team);
        $theirs = AgentToken::factory()->forMember($member, $team)->create(['name' => 'CI runner']);
        $mine = AgentToken::factory()->forMember($member, $team)->create();

        $this->actingAs($member);
        Livewire::test('pages::tokens.index')->call('revokeToken', $mine->id);
        $this->assertSame(0, $this->sentTo($member, 'agent-token-revoked'));

        $this->actingAs($owner);
        Livewire::test('pages::tokens.index')->call('revokeToken', $theirs->id);
        $this->assertSame(1, $this->sentTo($member, 'agent-token-revoked'));
    }

    public function test_being_removed_from_a_team_is_mailed(): void
    {
        [$team, $owner, $member] = $this->sharedTeam();
        $this->actingAs($owner);

        Livewire::test('pages::teams.remove-member-modal', ['team' => $team, 'memberId' => $member->id])->call('removeMember');

        $this->assertSame(1, $this->sentTo($member, 'removed-from-team'));
        $this->assertSame(0, $this->sentTo($owner, 'removed-from-team'));
    }

    public function test_deleting_a_team_is_mailed_to_its_other_members_and_frees_its_domains(): void
    {
        [$team, $owner, $member] = $this->sharedTeam();
        $domain = CustomDomain::factory()->forMember($owner, $team)->connected()->create();
        $this->actingAs($owner);

        Livewire::test('pages::teams.delete-team-modal', ['team' => $team])->set('deleteName', 'Platform')->call('deleteTeam');

        $this->assertSoftDeleted($team);
        $this->assertSame(1, $this->sentTo($member, 'team-deleted'));
        $this->assertSame(0, $this->sentTo($owner, 'team-deleted'));
        $this->assertModelMissing($domain);
    }
}
