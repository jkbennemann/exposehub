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
use App\Support\Sso\PublicHost;
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
        app(AdminActions::class)->approveSso($this->admin, $connection, $connection->fingerprint());

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

    public function test_the_login_page_shows_one_way_in_at_a_time(): void
    {
        // Without a provider there is nothing to switch to, and one email field.
        $this->get(route('login'))->assertOk()
            ->assertSee('data-test="auth-panel"', false)
            ->assertDontSee('data-test="sso-switch"', false)
            ->assertDontSee('name="address"', false);

        $this->approved();

        // The page opens on the password. Single sign-on by address is a view of its own,
        // hidden until someone asks for it.
        $page = $this->get(route('login'))->assertOk()->assertSee('data-test="sso-switch"', false)->getContent();
        $this->assertSame(1, substr_count($page, 'name="address"'));
        $this->assertMatchesRegularExpression('/x-show="byAddress"\s+x-cloak/', $page);
        $this->assertDoesNotMatchRegularExpression('/x-show="! byAddress"\s+x-cloak/', $page);

        // Someone whose attempt by address failed comes back to that view and reads why there.
        $page = $this->followingRedirects()->get(route('sso.redirect', ['address' => 'someone@unknown.example']))
            ->assertOk()->assertSee('data-test="sso-error"', false)->getContent();
        $this->assertMatchesRegularExpression('/x-show="! byAddress"\s+x-cloak/', $page);
        $this->assertDoesNotMatchRegularExpression('/x-show="byAddress"\s+x-cloak/', $page);
    }

    public function test_an_admin_approves_and_people_then_sign_in_by_their_address_and_join_the_team(): void
    {
        $this->enter();
        $connection = SsoConnection::query()->sole();

        // Only a platform admin approves, on the admin page.
        try {
            app(AdminActions::class)->approveSso($this->owner, $connection, $connection->fingerprint());
            $this->fail('The team\'s owner approved their own provider.');
        } catch (AuthorizationException) {
            $this->assertNull($connection->fresh()->approved_at);
        }
        // The admin area does not exist for anyone else.
        $this->actingAs($this->owner)->get(route('admin.sso'))->assertNotFound();
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        Livewire::test('pages::admin.sso')->assertSee('Blue')->assertSee(self::TEAM_ISSUER)->assertSee('blue.example')
            ->call('approve', $connection->id, $connection->fingerprint());
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
        $this->assertSame('another-secret', $save(['clientId' => 'blue-client-3'])->client_secret);
        $this->assertNotNull(SsoConnection::query()->sole()->approved_at);

        // The name is what people read in messages and mails about their account: a new one is approved again.
        $this->assertNull($save(['name' => 'Install IT (call 555 0100)'])->approved_at);
        app(AdminActions::class)->approveSso($this->admin, SsoConnection::query()->sole(), SsoConnection::query()->sole()->fingerprint());

        $this->assertNull($save(['domains' => 'blue.example, raidboxes.example'])->approved_at);
        $this->assertFalse(Sso::enabled());
        $this->get(route('sso.redirect', ['address' => 'ada@blue.example']))->assertNotFound();

        app(AdminActions::class)->approveSso($this->admin, SsoConnection::query()->sole(), SsoConnection::query()->sole()->fingerprint());
        $this->assertNull($save(['issuer' => 'https://login.other.example.org'])->approved_at);
        app(AdminActions::class)->approveSso($this->admin, SsoConnection::query()->sole(), SsoConnection::query()->sole()->fingerprint());
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
            app(AdminActions::class)->approveSso($this->admin, $claim, $claim->fingerprint());
            $this->fail('A second provider was approved for a domain that has one.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('blue.example', $e->getMessage());
        }
        $this->assertNull($claim->fresh()->approved_at);

        // The install's own provider takes precedence in the same way.
        config(['exposehub.sso.issuer' => self::ISSUER, 'exposehub.sso.domains' => ['rival.example']]);
        try {
            app(AdminActions::class)->approveSso($this->admin, $claim, $claim->fingerprint());
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

        app(AdminActions::class)->approveSso($this->admin, $connection->fresh(), $connection->fresh()->fingerprint());
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

    /**
     * Found by the second security review: the page showed one thing and the
     * click approved whatever was stored by then. The team's owner swapped the
     * domains in between and was approved for a company that was not theirs.
     */
    public function test_an_admin_approves_what_they_were_shown_and_nothing_that_changed_since(): void
    {
        $boss = User::factory()->create(['email' => 'boss@victim-corp.example', 'is_admin' => true]);
        $this->enter(['domains' => 'red-corp.example']);
        $shown = SsoConnection::query()->sole();

        // The admin has the page open, with what was entered.
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        $page = Livewire::test('pages::admin.sso')->assertSee('red-corp.example');

        // Meanwhile the owner asks for someone else's domain.
        Livewire::actingAs($this->owner)->test('pages::teams.sso', ['team' => $this->team])->set('domains', 'victim-corp.example')->call('save')->assertHasNoErrors();

        $this->actingAs($this->admin);
        $page->call('approve', $shown->id, $shown->fingerprint());

        $this->assertNull(SsoConnection::query()->sole()->approved_at);
        $this->assertFalse(Sso::enabled());
        $this->assertSame(0, AdminAction::query()->where('action', 'sso.approved')->count());
        try {
            app(AdminActions::class)->approveSso($this->admin, $shown, $shown->fingerprint());
            $this->fail('An entry that changed after it was shown was approved.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('changed after you opened the page', $e->getMessage());
        }

        // Approving what is there now takes reading it: the fingerprint of the entry as it stands.
        $now = SsoConnection::query()->sole();
        $this->assertNotSame($shown->fingerprint(), $now->fingerprint());
        app(AdminActions::class)->approveSso($this->admin, $now, $now->fingerprint());
        $this->assertNotNull($now->fresh()->approved_at);
        $this->assertTrue($boss->fresh()->is_admin);
    }

    /**
     * Also from that review: an approved domain handed every account on it
     * to the team's provider, admins of the install included.
     */
    public function test_a_teams_provider_never_signs_in_an_admin_of_the_install(): void
    {
        config(['exposehub.node_secret' => 'node-secret-for-tests']);
        $boss = User::factory()->create(['email' => 'boss@blue.example', 'is_admin' => true]);
        $colleague = User::factory()->create(['email' => 'grace@blue.example']);
        $bossToken = app(IssueAgentToken::class)->handle($boss, $boss->currentTeam, 'laptop')[0];
        $connection = $this->enter();

        // The page says what an approval hands over, and what it does not.
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        Livewire::test('pages::admin.sso')
            ->assertSee('2 accounts already have such an address')
            ->assertSee('One of them administers this service and stays out of it');
        app(AdminActions::class)->approveSso($this->admin, $connection, $connection->fingerprint());
        $this->post(route('logout'));

        // The provider vouches for the admin's address: no sign-in, no tie, no place in the team.
        $this->signInAt('boss@blue.example')->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->assertGuest();
        $this->assertSame(0, $boss->identities()->count());
        $this->assertFalse($boss->fresh()->belongsToTeam($this->team));

        // The admin keeps the password, and tokens that outlast the provider's days.
        $this->assertFalse(Sso::requiredFor($boss));
        $this->post(route('login.store'), ['email' => $boss->email, 'password' => 'password']);
        $this->get(route('dashboard', ['current_team' => $boss->currentTeam->slug]))->assertOk();
        $this->post(route('logout'));
        $this->travel(40)->days();
        $accepted = collect($this->withToken('node-secret-for-tests')->getJson(route('internal.snapshot'))->assertOk()->json('tokens'))->pluck('id')->all();
        $this->assertContains($bossToken->id, $accepted);

        // An ordinary colleague of the domain is the provider's, as approved.
        $this->assertTrue(Sso::requiredFor($colleague));
        $this->signInAt('grace@blue.example');
        $this->assertAuthenticatedAs($colleague);
    }

    /**
     * Also from that review: the issuer was checked by its spelling only, and
     * the addresses a provider names in its discovery document not at all.
     */
    public function test_a_teams_provider_cannot_send_the_server_to_addresses_that_are_not_public(): void
    {
        $this->approved();
        cache()->flush();

        // The issuer's name resolves into the network this service runs in.
        $this->hostAddresses = ['login.blue.example.org' => null];
        $this->get(route('sso.redirect', ['address' => 'ada@blue.example']))->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        Http::assertNothingSent();

        // The issuer is public, but its discovery document names an inside address for the keys and the token.
        $this->hostAddresses = ['metadata.blue.example.org' => null];
        $this->discoveryOverrides = ['token_endpoint' => 'https://metadata.blue.example.org/token', 'jwks_uri' => 'https://metadata.blue.example.org/keys'];
        $sent = $this->startSignIn(['address' => 'ada@blue.example'], self::TEAM_ISSUER);
        $this->nextIdToken = $this->idToken($sent['nonce'], ['iss' => self::TEAM_ISSUER, 'aud' => 'blue-client', 'email' => 'ada@blue.example']);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('sso');
        $this->assertGuest();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'metadata.blue.example.org'));

        // Another port, or no https, is refused the same way.
        foreach (['https://login.blue.example.org:8443/token', 'http://login.blue.example.org/token'] as $endpoint) {
            cache()->flush();
            $this->hostAddresses = [];
            $this->discoveryOverrides = ['token_endpoint' => $endpoint];
            $sent = $this->get(route('sso.redirect', ['address' => 'ada@blue.example']));
            if ($sent->isRedirect(route('login'))) {
                continue;
            }
            parse_str((string) parse_url((string) $sent->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->nextIdToken = $this->idToken($query['nonce'], ['iss' => self::TEAM_ISSUER, 'aud' => 'blue-client', 'email' => 'ada@blue.example']);
            $this->get(route('sso.callback', ['state' => $query['state'], 'code' => 'code']))->assertSessionHasErrors('sso');
            $this->assertGuest();
        }

        // The install's own provider is the operator's and may be anywhere, also inside.
        cache()->flush();
        $this->discoveryOverrides = [];
        $this->hostAddresses = ['id.example.org' => null];
        config(['exposehub.sso.issuer' => self::ISSUER, 'exposehub.sso.domains' => ['corp.example']]);
        $this->signInThroughProvider();
        $this->assertAuthenticated();
    }

    public function test_only_addresses_on_the_public_internet_count_as_public(): void
    {
        $host = new PublicHost;

        foreach (['10.0.0.5', '172.16.4.1', '192.168.1.1', '127.0.0.1', '169.254.169.254', '100.64.0.1', '100.127.255.254', '0.0.0.0', '::1', 'fe80::1', 'fd00::1', '::ffff:10.0.0.5'] as $inside) {
            $this->assertFalse($host->isPublic($inside), $inside.' counts as public');
        }
        foreach (['93.184.216.34', '100.63.255.255', '100.128.0.1', '2606:2800:220:1:248:1893:25c8:1946'] as $outside) {
            $this->assertTrue($host->isPublic($outside), $outside.' does not count as public');
        }
    }
}
