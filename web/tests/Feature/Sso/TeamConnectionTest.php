<?php

namespace Tests\Feature\Sso;

use App\Actions\Admin\AdminActions;
use App\Actions\Tokens\IssueAgentToken;
use App\Enums\TeamRole;
use App\Models\AdminAction;
use App\Models\AgentToken;
use App\Models\SsoConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Demo;
use App\Support\Sso\Sso;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Security\ReplaysLivewireSnapshots;
use Tests\TestCase;

/**
 * A team's own identity provider: its owner enters it, a platform admin
 * approves it, and whoever it signs in becomes a member of the team.
 */
class TeamConnectionTest extends TestCase
{
    use FakesIdentityProvider;
    use RefreshDatabase;
    use ReplaysLivewireSnapshots;

    private const string TEAM_ISSUER = 'https://login.blue.example.org';

    private User $admin;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // The install has no provider of its own in these tests unless one says so.
        $this->setUpSso(['exposehub.sso.issuer' => '', 'exposehub.admin_emails' => ['root@install.example']]);
        $this->providerIssuers = [self::ISSUER, self::TEAM_ISSUER];

        $this->admin = User::factory()->create(['email' => 'root@install.example']);
        $this->owner = User::factory()->create(['email' => 'lead@elsewhere.example']);
        $this->team = Team::factory()->create(['name' => 'Blue', 'is_personal' => false]);
        $this->team->memberships()->create(['user_id' => $this->owner->id, 'role' => TeamRole::Owner]);
        $this->owner->switchTeam($this->team);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function enter(array $changes = []): SsoConnection
    {
        $this->actingAs($this->owner);
        Livewire::test('pages::teams.sso', ['team' => $this->team])
            ->set('name', 'Okta')->set('issuer', self::TEAM_ISSUER)->set('clientId', 'blue-client')
            ->set('clientSecret', 'blue-secret-value')->set('domains', 'Blue.Example, @blue-group.example')
            ->set($changes)
            ->call('save')->assertHasNoErrors();
        $this->post(route('logout'));

        return SsoConnection::query()->where('team_id', $this->team->id)->sole();
    }

    private function approved(array $changes = []): SsoConnection
    {
        $connection = $this->enter($changes);
        app(AdminActions::class)->approveSso($this->admin, $connection);

        return $connection->fresh();
    }

    /**
     * Sign in through the team's provider as someone with an address of its domain.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signInAt(string $address, array $claims = []): TestResponse
    {
        return $this->signInThroughProvider(['email' => $address, 'sub' => 'blue|'.$address, ...$claims], ['address' => $address], self::TEAM_ISSUER);
    }

    public function test_the_teams_owner_enters_the_provider_and_nothing_happens_until_an_admin_approves(): void
    {
        $connection = $this->enter();

        $this->assertSame(['blue.example', 'blue-group.example'], $connection->domains);
        $this->assertNull($connection->approved_at);
        $this->assertSame($this->owner->id, $connection->created_by);
        // The secret is stored encrypted, and is not part of what the model shows.
        $stored = DB::table('sso_connections')->where('id', $connection->id)->value('client_secret');
        $this->assertStringNotContainsString('blue-secret-value', (string) $stored);
        $this->assertSame('blue-secret-value', $connection->client_secret);
        $this->assertArrayNotHasKey('client_secret', $connection->toArray());

        // Not in force: no way in through it, nobody has to use it, and the server has not contacted it.
        $this->assertFalse(Sso::enabled());
        $this->get(route('login'))->assertOk()->assertDontSee('data-test="sso-sign-in"', false);
        $this->get(route('sso.redirect', ['address' => 'ada@blue.example']))->assertNotFound();
        $colleague = User::factory()->create(['email' => 'ada@blue.example']);
        $this->actingAs($colleague)->get(route('dashboard', ['current_team' => $colleague->currentTeam->slug]))->assertOk();
        Http::assertNothingSent();
    }

    public function test_only_the_owner_of_a_shared_team_may_enter_a_provider(): void
    {
        $teamAdmin = User::factory()->create();
        $this->team->memberships()->create(['user_id' => $teamAdmin->id, 'role' => TeamRole::Admin]);
        $member = User::factory()->create();
        $this->team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

        foreach ([$teamAdmin, $member, $this->admin] as $other) {
            $this->assertFalse($other->can('manageSso', $this->team));
            $this->actingAs($other);
            Livewire::test('pages::teams.sso', ['team' => $this->team])->assertForbidden();
        }
        $this->assertFalse($this->owner->can('manageSso', $this->owner->personalTeam()));
        config(['exposehub.demo.enabled' => true]);
        $this->assertFalse(Demo::admit()->can('manageSso', Demo::team()));

        // The team page shows the form to the owner and to nobody else.
        $this->actingAs($this->owner)->get(route('teams.edit', ['team' => $this->team->slug]))->assertOk()->assertSee('data-test="team-sso"', false);
        $this->actingAs($teamAdmin)->get(route('teams.edit', ['team' => $this->team->slug]))->assertOk()->assertDontSee('data-test="team-sso"', false);

        // A form the owner had open does not work for someone else.
        $html = $this->actingAs($this->owner)->get(route('teams.edit', ['team' => $this->team->slug]))->getContent();
        $snapshot = $this->snapshotOf($html, 'pages::teams.sso');
        $this->actingAs($teamAdmin);
        $this->replay($snapshot, ['name' => 'Evil', 'issuer' => 'https://id.evil.example.org', 'clientId' => 'x', 'clientSecret' => 'y', 'domains' => 'blue.example'], [['save']])->assertForbidden();
        $this->assertSame(0, SsoConnection::query()->count());
    }

    public function test_what_the_owner_enters_has_to_be_a_public_https_issuer_and_real_domains(): void
    {
        $this->actingAs($this->owner);
        $form = fn () => Livewire::test('pages::teams.sso', ['team' => $this->team])
            ->set('name', 'Okta')->set('issuer', self::TEAM_ISSUER)->set('clientId', 'c')->set('clientSecret', 's')->set('domains', 'blue.example');

        foreach ([
            'http://login.blue.example.org', 'https://10.0.0.5', 'https://localhost', 'https://metadata.internal', 'https://kubernetes.default.svc',
            'https://login.blue.example.org:8443', 'https://user:pw@login.blue.example.org', 'https://login.blue.example.org/?x=1',
            'https://[::1]', 'ftp://login.blue.example.org', 'login.blue.example.org', 'https://exposehub-web.exposehub.svc.cluster.local',
        ] as $issuer) {
            $form()->set('issuer', $issuer)->call('save')->assertHasErrors('issuer');
        }
        foreach (['', 'not a domain', 'blue', 'demo.invalid', 'a.example,b.example,c.example,d.example,e.example,f.example,g.example,h.example,i.example,j.example,k.example', 'ada@blue.example/x'] as $domains) {
            $form()->set('domains', $domains)->call('save')->assertHasErrors();
        }
        $form()->set('clientSecret', '')->call('save')->assertHasErrors('client_secret');
        $form()->set('name', '<b>Okta</b>')->call('save')->assertHasErrors('name');
        $this->assertSame(0, SsoConnection::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_admin_approves_and_people_then_sign_in_by_their_address_and_join_the_team(): void
    {
        $this->enter();
        $connection = SsoConnection::query()->sole();

        // Only a platform admin approves, on the admin page.
        try {
            app(AdminActions::class)->approveSso($this->owner, $connection);
            $this->fail('The team\'s owner approved their own provider.');
        } catch (AuthorizationException) {
            $this->assertNull($connection->fresh()->approved_at);
        }
        // The admin area does not exist for anyone else.
        $this->actingAs($this->owner)->get(route('admin.sso'))->assertNotFound();
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        Livewire::test('pages::admin.sso')->assertSee('Blue')->assertSee(self::TEAM_ISSUER)->assertSee('blue.example')
            ->call('approve', $connection->id);
        $this->assertNotNull($connection->fresh()->approved_at);
        $this->assertSame($this->admin->id, $connection->fresh()->approved_by);
        $entry = AdminAction::query()->where('action', 'sso.approved')->sole();
        $this->assertSame(['Blue', self::TEAM_ISSUER], [$entry->subject_label, $entry->details['issuer']]);
        $this->post(route('logout'));

        // The login page asks for the address; it names no team and no provider.
        $this->get(route('login'))->assertOk()->assertSee('data-test="sso-by-address"', false)->assertDontSee('data-test="sso-button"', false)->assertDontSee('Blue');
        $this->get(route('sso.redirect', ['address' => 'someone@unknown.example']))->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->get(route('sso.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('sso');

        $sent = $this->startSignIn(['address' => 'Ada@Blue.Example'], self::TEAM_ISSUER);
        $this->assertSame(['blue-client', 'ada@blue.example'], [$sent['client_id'], $sent['login_hint']]);

        $this->signInAt('ada@blue.example', ['name' => 'Ada Lovelace']);
        $ada = User::query()->where('email', 'ada@blue.example')->sole();
        $this->assertAuthenticatedAs($ada);
        $this->assertSame(TeamRole::Member, $ada->teamRole($this->team));
        $this->assertTrue($ada->fresh()->currentTeam->is($this->team));
        $this->assertNotNull($ada->personalTeam());
        $this->assertSame(self::TEAM_ISSUER, $ada->identities()->sole()->issuer);
        Http::assertSent(fn ($request) => $request->url() === self::TEAM_ISSUER.'/token' && $request->hasHeader('Authorization', 'Basic '.base64_encode('blue-client:blue-secret-value')));
    }

    public function test_whoever_the_provider_lets_in_is_a_member_also_after_being_removed_here(): void
    {
        $this->approved();
        $this->signInAt('ada@blue.example');
        $ada = User::query()->where('email', 'ada@blue.example')->sole();
        $this->post(route('logout'));

        $this->team->memberships()->where('user_id', $ada->id)->delete();
        $this->signInAt('ada@blue.example');

        $this->assertSame(TeamRole::Member, $ada->fresh()->teamRole($this->team));
        $this->assertSame(1, $this->team->memberships()->where('user_id', $ada->id)->count());

        // Someone who was given a higher role here keeps it.
        $this->team->memberships()->where('user_id', $ada->id)->update(['role' => TeamRole::Admin]);
        $this->post(route('logout'));
        $this->signInAt('ada@blue.example');
        $this->assertSame(TeamRole::Admin, $ada->fresh()->teamRole($this->team));
    }

    public function test_accounts_of_the_teams_domains_sign_in_through_its_provider_only(): void
    {
        $colleague = User::factory()->create(['email' => 'grace@blue.example']);
        $this->approved();

        $this->post(route('login.store'), ['email' => $colleague->email, 'password' => 'password']);
        $this->get(route('dashboard', ['current_team' => $colleague->currentTeam->slug]))
            ->assertRedirect(route('login'))->assertSessionHas('status', 'Your account signs in with Okta. Use single sign-on on this page.');
        $this->assertGuest();

        $this->post(route('register.store'), ['name' => 'M', 'email' => 'new@blue-group.example', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
            ->assertSessionHasErrors('email');

        // The owner's own address is of another domain: nothing changes for them.
        $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password']);
        $this->get(route('dashboard', ['current_team' => $this->team->slug]))->assertOk();
    }

    public function test_a_change_to_the_issuer_or_the_domains_has_to_be_approved_again(): void
    {
        $this->approved();
        $save = function (array $changes) {
            $this->actingAs($this->owner);
            Livewire::test('pages::teams.sso', ['team' => $this->team])->set($changes)->call('save')->assertHasNoErrors();
            $this->post(route('logout'));

            return SsoConnection::query()->sole();
        };

        // A new client id or secret is the same provider answering for the same domains.
        $this->assertNotNull($save(['clientId' => 'blue-client-2', 'clientSecret' => 'another-secret'])->approved_at);
        $this->assertSame('another-secret', SsoConnection::query()->sole()->client_secret);
        // Leaving the secret empty keeps the stored one.
        $this->assertSame('another-secret', $save(['name' => 'Okta (Blue)'])->client_secret);
        $this->assertNotNull(SsoConnection::query()->sole()->approved_at);

        $this->assertNull($save(['domains' => 'blue.example, raidboxes.example'])->approved_at);
        $this->assertFalse(Sso::enabled());
        $this->get(route('sso.redirect', ['address' => 'ada@blue.example']))->assertNotFound();

        app(AdminActions::class)->approveSso($this->admin, SsoConnection::query()->sole());
        $this->assertNull($save(['issuer' => 'https://login.other.example.org'])->approved_at);
        app(AdminActions::class)->approveSso($this->admin, SsoConnection::query()->sole());
        $this->assertNull($save(['takesUnconfirmedAddresses' => true])->approved_at);
    }

    public function test_a_domain_belongs_to_one_provider(): void
    {
        $this->approved();

        // Another team asks for a domain that is taken.
        $rival = User::factory()->create();
        $other = Team::factory()->create(['name' => 'Rival', 'is_personal' => false]);
        $other->memberships()->create(['user_id' => $rival->id, 'role' => TeamRole::Owner]);
        $this->actingAs($rival);
        Livewire::test('pages::teams.sso', ['team' => $other])
            ->set('name', 'Rival ID')->set('issuer', 'https://id.rival.example.org')->set('clientId', 'r')->set('clientSecret', 's')->set('domains', 'rival.example, blue.example')
            ->call('save')->assertHasNoErrors();
        $claim = SsoConnection::query()->where('team_id', $other->id)->sole();

        try {
            app(AdminActions::class)->approveSso($this->admin, $claim);
            $this->fail('A second provider was approved for a domain that has one.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('blue.example', $e->getMessage());
        }
        $this->assertNull($claim->fresh()->approved_at);

        // The install's own provider takes precedence in the same way.
        config(['exposehub.sso.issuer' => self::ISSUER, 'exposehub.sso.domains' => ['rival.example']]);
        try {
            app(AdminActions::class)->approveSso($this->admin, $claim);
            $this->fail('A team was approved for a domain of the install\'s own provider.');
        } catch (AuthorizationException) {
        }
        // And if a second claim were in force all the same, the first provider keeps the domain.
        $claim->forceFill(['approved_at' => now()])->save();
        $this->assertSame('team-'.$this->team->id, Sso::forEmail('ada@blue.example')->key);
        $this->assertSame('install', Sso::forEmail('ada@rival.example')->key);
        $this->assertNull(Sso::find('team-'.$other->id));
    }

    public function test_a_sign_in_started_with_one_provider_cannot_be_finished_with_anothers_token(): void
    {
        $this->approved();
        config(['exposehub.sso.issuer' => self::ISSUER, 'exposehub.sso.domains' => ['corp.example']]);

        // Started for the team's provider; the token comes from the install's provider, for its client.
        $sent = $this->startSignIn(['address' => 'ada@blue.example'], self::TEAM_ISSUER);
        $this->nextIdToken = $this->idToken($sent['nonce'], ['email' => 'ada@blue.example']);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('sso');
        $this->assertGuest();

        // The team's provider vouching for an address of the install's provider's domain.
        $this->signInThroughProvider(['email' => 'boss@corp.example', 'sub' => 'blue|boss'], ['address' => 'ada@blue.example'], self::TEAM_ISSUER)->assertSessionHasErrors('sso');
        $this->assertGuest();
        $this->assertSame(0, User::query()->whereIn('email', ['ada@blue.example', 'boss@corp.example'])->count());

        // Each provider signs in its own.
        $this->signInThroughProvider();
        $this->assertFalse(User::query()->where('email', 'ada@corp.example')->sole()->belongsToTeam($this->team));
    }

    public function test_withdrawing_the_approval_or_removing_the_provider_ends_it(): void
    {
        $connection = $this->approved();
        $this->signInAt('ada@blue.example');
        $ada = User::query()->where('email', 'ada@blue.example')->sole();
        $token = app(IssueAgentToken::class)->handle($ada, $this->team, 'laptop')[0];
        $this->post(route('logout'));

        app(AdminActions::class)->revokeSso($this->admin, $connection);
        $this->assertSame(1, AdminAction::query()->where('action', 'sso.revoked')->count());
        $this->get(route('sso.redirect', ['address' => 'ada@blue.example']))->assertNotFound();
        // Ada keeps her account, her place in the team and her tokens; she is nobody's to sign in any more.
        $this->assertTrue($ada->fresh()->belongsToTeam($this->team));
        $this->assertTrue(AgentToken::query()->usable()->whereKey($token->id)->exists());
        $this->assertFalse(Sso::requiredFor($ada));

        app(AdminActions::class)->approveSso($this->admin, $connection->fresh());
        $this->actingAs($this->owner);
        Livewire::test('pages::teams.sso', ['team' => $this->team])->call('remove');
        $this->assertSame(0, SsoConnection::query()->count());
        $this->assertFalse(Sso::enabled());

        // Deleting the team takes its provider with it.
        $this->approved();
        $this->team->forceDelete();
        $this->assertSame(0, SsoConnection::query()->count());
    }

    public function test_tokens_of_a_teams_accounts_need_a_recent_sign_in_counted_from_the_approval(): void
    {
        config(['exposehub.node_secret' => 'node-secret-for-tests']);
        $accepted = fn () => collect($this->withToken('node-secret-for-tests')->getJson(route('internal.snapshot'))->assertOk()->json('tokens'))->pluck('id')->sort()->values()->all();
        $old = User::factory()->create(['email' => 'grace@blue.example']);
        $oldToken = app(IssueAgentToken::class)->handle($old, $old->currentTeam, 'laptop')[0];
        $ownerToken = app(IssueAgentToken::class)->handle($this->owner, $this->team, 'laptop')[0];

        $this->travel(40)->days();
        $this->approved();
        $this->signInAt('ada@blue.example');
        $ada = User::query()->where('email', 'ada@blue.example')->sole();
        $adaToken = app(IssueAgentToken::class)->handle($ada, $this->team, 'laptop')[0];
        $this->post(route('logout'));

        // Approved today: an account of the domain that has not signed in through it yet keeps its tunnels for now.
        $this->assertSame([$oldToken->id, $ownerToken->id, $adaToken->id], $accepted());

        $this->travel(31)->days();
        $this->assertSame([$ownerToken->id], $accepted());

        $this->signInAt('ada@blue.example');
        $this->assertSame([$ownerToken->id, $adaToken->id], $accepted());
    }
}
