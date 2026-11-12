<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAction;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\RequestLog;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\ServiceStatus;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.admin_emails' => ['owner@example.com'], 'exposehub.node_secret' => self::SECRET]);
        Notification::fake();
    }

    private function owner(): User
    {
        return User::factory()->create(['email' => 'owner@example.com']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_owners_and_granted_admins_get_in_and_nobody_else_learns_the_area_exists(): void
    {
        $this->as($this->owner())->get(route('admin.overview'))->assertOk()->assertSee('Every change here is recorded');
        $this->as($this->admin())->get(route('admin.people'))->assertOk();

        $this->as(User::factory()->create())->get(route('admin.overview'))->assertNotFound();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('admin.overview'))->assertRedirect(route('login'));
    }

    public function test_an_unverified_owner_address_is_not_an_owner(): void
    {
        $impostor = User::factory()->unverified()->create(['email' => 'owner@example.com']);

        $this->assertFalse($impostor->isOwner());
        $this->assertFalse($impostor->isAdmin());
    }

    public function test_the_admin_area_asks_for_the_password_again(): void
    {
        $this->actingAs($this->owner())->get(route('admin.overview'))->assertRedirect(route('password.confirm'));
    }

    public function test_suspending_signs_out_cuts_tokens_and_mails_the_person(): void
    {
        $owner = $this->owner();
        $person = User::factory()->create();
        $token = AgentToken::factory()->forMember($person, $person->currentTeam)->create();

        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $person])
            ->set('reason', 'Phishing page on a tunnel')
            ->call('suspend')
            ->assertHasNoErrors();

        $person->refresh();
        $this->assertTrue($person->isSuspended());
        $this->assertSame('Phishing page on a tunnel', $person->suspension_reason);
        $this->assertFalse(AgentToken::usable()->whereKey($token->id)->exists());
        Notification::assertSentTo($person, AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'account-suspended');
        $this->assertDatabaseHas('admin_actions', ['action' => 'user.suspended', 'subject_id' => $person->id, 'admin_id' => $owner->id]);

        // Their next request signs them out and says why.
        $this->actingAs($person)->get(route('dashboard', ['current_team' => $person->currentTeam->slug]))
            ->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest();

        // The tunnel server no longer gets the token.
        $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertJsonCount(0, 'tokens');
    }

    public function test_reactivating_brings_the_tokens_back(): void
    {
        $owner = $this->owner();
        $person = User::factory()->create(['suspended_at' => now()]);
        $token = AgentToken::factory()->forMember($person, $person->currentTeam)->create();

        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $person])->call('reactivate');

        $this->assertFalse($person->refresh()->isSuspended());
        $this->assertTrue(AgentToken::usable()->whereKey($token->id)->exists());
        Notification::assertSentTo($person, AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'account-reactivated');
    }

    public function test_nobody_acts_on_an_owner_or_on_themselves_and_only_owners_act_on_admins(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        Livewire::actingAs($admin)->test('pages::admin.person', ['user' => $owner])->call('suspend');
        Livewire::actingAs($admin)->test('pages::admin.person', ['user' => $admin])->call('suspend');
        Livewire::actingAs($admin)->test('pages::admin.person', ['user' => $otherAdmin])->call('suspend');
        $this->assertFalse($owner->refresh()->isSuspended());
        $this->assertFalse($admin->refresh()->isSuspended());
        $this->assertFalse($otherAdmin->refresh()->isSuspended());

        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $otherAdmin])->call('suspend');
        $this->assertTrue($otherAdmin->refresh()->isSuspended());
        $this->assertFalse($otherAdmin->isAdmin(), 'a suspended admin is no admin');
    }

    public function test_only_owners_hand_out_and_take_back_admin_rights(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        $person = User::factory()->create();

        Livewire::actingAs($admin)->test('pages::admin.person', ['user' => $person])->call('grantAdmin');
        $this->assertFalse($person->refresh()->is_admin);

        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $person])->call('grantAdmin');
        $this->assertTrue($person->refresh()->isAdmin());
        Notification::assertSentTo($person, AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'admin-granted');

        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $person])->call('revokeAdmin');
        $this->assertFalse($person->refresh()->isAdmin());
    }

    public function test_an_admin_can_delete_an_account_after_typing_its_address(): void
    {
        $owner = $this->owner();
        $person = User::factory()->create(['email' => 'gone@example.com']);

        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $person])
            ->set('confirmEmail', 'wrong@example.com')->call('delete')->assertHasErrors('confirmEmail');
        $this->assertModelExists($person);

        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $person])
            ->set('confirmEmail', 'gone@example.com')->call('delete')->assertRedirect(route('admin.people'));
        $this->assertModelMissing($person);
        Notification::assertSentOnDemand(AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'account-deleted-by-admin');
        $this->assertDatabaseHas('admin_actions', ['action' => 'user.deleted', 'subject_label' => "{$person->name} <gone@example.com>"]);
    }

    public function test_an_admin_can_confirm_an_email_address(): void
    {
        $person = User::factory()->unverified()->create();

        Livewire::actingAs($this->owner())->test('pages::admin.person', ['user' => $person])->call('verifyEmail');

        $this->assertTrue($person->refresh()->hasVerifiedEmail());
        Notification::assertSentTo($person, AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'email-verified-by-admin');
    }

    public function test_admins_revoke_tokens_and_remove_domains_of_any_team(): void
    {
        $owner = $this->owner();
        $person = User::factory()->create();
        $team = $person->currentTeam;
        $token = AgentToken::factory()->forMember($person, $team)->create();
        $domain = CustomDomain::factory()->for($team)->create(['user_id' => $person->id, 'verified_at' => now()]);

        Livewire::actingAs($owner)->test('pages::admin.teams')
            ->set('personal', true)->call('toggle', $team->id)
            ->call('revokeToken', $token->id)
            ->call('removeDomain', $domain->id);

        $this->assertNotNull($token->refresh()->revoked_at);
        $this->assertModelMissing($domain);
        Notification::assertSentTo($person, AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'agent-token-revoked');
        Notification::assertSentTo($person, AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'domain-removed-by-admin');
        $this->assertSame(2, AdminAction::count());
    }

    public function test_admins_see_how_much_traffic_a_person_has_but_never_what(): void
    {
        $person = User::factory()->create();
        RequestLog::factory()->count(3)->create(['user_id' => $person->id, 'team_id' => $person->current_team_id, 'path' => '/secret-customer-path', 'status' => 500]);

        $this->as($this->owner())->get(route('admin.person', $person))
            ->assertOk()
            ->assertSeeInOrder(['3', 'requests, 24 hours'])
            ->assertDontSee('/secret-customer-path');
    }

    public function test_settings_saved_in_the_admin_area_override_config_and_can_be_reset(): void
    {
        $owner = $this->owner();
        config(['exposehub.allowed_email_domains' => ['example.com']]);
        Settings::apply();

        Livewire::actingAs($owner)->test('pages::admin.settings')
            ->set('allowed', "example.com\nexample.org")
            ->set('retention', '14')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['example.com', 'example.org'], config('exposehub.allowed_email_domains'));
        $this->assertSame(14, config('exposehub.log_retention_days'));
        $this->assertDatabaseHas('admin_actions', ['action' => 'settings.updated']);

        Livewire::actingAs($owner)->test('pages::admin.settings')->call('resetSetting', 'log_retention_days');
        $this->assertSame(Settings::default('log_retention_days'), config('exposehub.log_retention_days'));
    }

    public function test_settings_refuse_hidden_domains_that_are_not_allowed(): void
    {
        Livewire::actingAs($this->owner())->test('pages::admin.settings')
            ->set('allowed', 'example.com')->set('hidden', 'other.org')->call('save')
            ->assertHasErrors('hidden');
    }

    public function test_the_tunnel_server_reports_its_status_and_admins_see_live_tunnels(): void
    {
        $person = User::factory()->create();
        $token = AgentToken::factory()->forMember($person, $person->currentTeam)->create(['name' => 'Work laptop']);

        $this->withToken(self::SECRET)->postJson(route('internal.status'), [
            'server_version' => '0.7.0', 'agent_release' => '0.7.0', 'started_at' => now()->subHour()->toIso8601String(), 'request_logs' => true,
            'tunnels' => [['token_id' => (string) $token->id, 'account' => 'team', 'hosts' => ['shop.example.eu'], 'protected' => [], 'agent_version' => '0.6.1', 'connected_at' => now()->subMinutes(5)->toIso8601String()]],
        ])->assertNoContent();

        $this->assertTrue(ServiceStatus::reportIsFresh());
        $this->as($this->owner())->get(route('admin.tunnels'))
            ->assertOk()->assertSee('shop.example.eu')->assertSee('Work laptop')->assertSee($person->name);

        $this->withoutToken()->postJson(route('internal.status'), [])->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function statusReport(array $fields): void
    {
        $this->withToken(self::SECRET)->postJson(route('internal.status'), $fields + [
            'server_version' => '0.7.0', 'started_at' => now()->subHour()->toIso8601String(), 'request_logs' => true, 'tunnels' => [],
        ])->assertNoContent();
        $this->withoutToken();
    }

    public function test_the_overview_says_plainly_when_the_server_cannot_refresh_its_token_list(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));
        ServiceStatus::snapshotFetched();
        $this->statusReport(['tokens_stale' => true, 'tokens_refreshed_at' => '2026-11-12T09:40:00.5Z']);

        $this->as($this->owner())->get(route('admin.overview'))->assertOk()
            ->assertSee('The tunnel server cannot get its token list from this dashboard.')
            ->assertSee('tunnels opened with them are closed')
            ->assertSee('12.11.2026 09:40 UTC')
            ->assertSee('data-test="admin-tokens-stale"', false)
            ->assertDontSee('last fetched its tokens');
    }

    public function test_the_overview_has_no_stale_token_warning_while_the_list_is_current(): void
    {
        ServiceStatus::snapshotFetched();
        $this->statusReport(['tokens_stale' => false, 'tokens_refreshed_at' => now()->subSeconds(10)->toIso8601String()]);

        $this->as($this->owner())->get(route('admin.overview'))->assertOk()
            ->assertDontSee('cannot get its token list')
            ->assertDontSee('data-test="admin-tokens-stale"', false)
            ->assertSee('Nothing right now.');
    }
}
