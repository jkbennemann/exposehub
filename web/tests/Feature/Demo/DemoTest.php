<?php

namespace Tests\Feature\Demo;

use App\Actions\Tokens\IssueAgentToken;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\LogGrant;
use App\Models\RequestLog;
use App\Models\SavedAddress;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exposehub.demo.enabled' => true,
            'exposehub.tunnel_domain' => 'tunnels.example',
            'exposehub.node_secret' => self::SECRET,
        ]);
    }

    /**
     * A visitor who clicked "Try the demo", signed in.
     */
    private function visitor(): User
    {
        $visitor = Demo::admit();
        $this->assertNotNull($visitor);
        $this->actingAs($visitor);

        return $visitor;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk()->json();
    }

    public function test_the_landing_page_offers_the_demo_only_while_it_is_on(): void
    {
        config(['exposehub.example_address' => 'https://your-name.tunnels.example']);

        // The film's example address is still an address, with the demo on.
        $this->get(route('home'))->assertOk()->assertSee('Try the demo')->assertSee('deleted every 30 minutes')
            ->assertSee('https://demo.tunnels.example');

        config(['exposehub.demo.enabled' => false]);

        $this->get(route('home'))->assertOk()->assertDontSee('Try the demo');
        $this->post(route('demo.enter'))->assertNotFound();
        $this->assertSame(0, User::query()->where('is_demo', true)->count());
    }

    public function test_one_click_signs_a_visitor_in_without_an_account(): void
    {
        Notification::fake();

        $response = $this->post(route('demo.enter'));

        $visitor = User::query()->where('is_demo', true)->where('email', 'like', 'visitor-%')->sole();
        $team = Demo::team();
        $this->assertNotNull($team);
        $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
        $this->assertAuthenticatedAs($visitor);
        $this->assertStringEndsWith('@demo.invalid', $visitor->email);
        $this->assertSame('member', $visitor->teamRole($team)?->value);
        $this->assertFalse($visitor->isAdmin());

        $this->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertSee('This is the demo.')
            ->assertSee('Leave the demo')
            ->assertDontSee($visitor->email);
        Notification::assertNothingSent();
    }

    public function test_the_demo_comes_with_sample_data_a_visitor_can_read_but_not_change(): void
    {
        $visitor = $this->visitor();
        $team = Demo::team();

        Livewire::test('pages::logs.index')->assertSee('/webhooks/payment')->assertSee('demo-shop.tunnels.example');
        $this->get(route('domains.index', ['current_team' => $team->slug]))->assertOk()->assertSee('dev.example.com');
        $this->get(route('access.index', ['current_team' => $team->slug]))->assertOk()->assertSee('demo-shop.tunnels.example')->assertSee('Office')
            ->assertSee('cannot be saved')->assertDontSee('data-test="address-save"', false);

        Livewire::test('pages::access.index')->set('name', 'paypal')->call('saveAddress')->assertHasErrors('name');
        $this->assertFalse(SavedAddress::query()->where('hostname', 'paypal.tunnels.example')->exists());
        Livewire::test('pages::domains.index')->assertSet('canManage', false);
        $this->assertFalse($visitor->can('create', [CustomDomain::class, $team]));
    }

    public function test_a_visitor_sees_their_own_tokens_tunnels_and_logs_and_no_other_visitors(): void
    {
        $first = $this->visitor();
        $team = Demo::team();
        [$token] = app(IssueAgentToken::class)->handle($first, $team, 'first-laptop');
        RequestLog::factory()->forMember($first, $team)->create(['path' => '/first-only', 'agent_token_id' => $token->id]);

        $second = $this->visitor();
        Livewire::test('pages::tokens.index')->assertDontSee('first-laptop')->assertDontSee('ada-laptop');
        Livewire::test('pages::logs.index')->assertDontSee('/first-only')->assertSee('/webhooks/payment');
        Livewire::test('pages::logs.index')->set('owner', (string) $first->id)->assertDontSee('/first-only');
        // Nobody to share logs with: the other members are strangers.
        Livewire::test('pages::logs.index')->assertCount('colleagues', 0)->set('shareWith', $first->id)->call('share')->assertForbidden();
        $this->assertFalse(LogGrant::query()->where('owner_id', $second->id)->exists());
        $this->assertFalse($second->can('revoke', $token));
        $this->assertFalse($second->can('disconnect', $token));

        $this->actingAs($first);
        Livewire::test('pages::tokens.index')->assertSee('first-laptop');
        Livewire::test('pages::logs.index')->assertSee('/first-only');
    }

    public function test_a_visitor_gets_a_few_tokens_and_no_mail_about_them(): void
    {
        Notification::fake();
        config(['exposehub.demo.tokens_per_visitor' => 2]);
        $visitor = $this->visitor();

        Livewire::test('pages::tokens.index')->set('name', 'one')->call('createToken')->assertHasNoErrors();
        Livewire::test('pages::tokens.index')->set('name', 'two')->call('createToken')->assertHasNoErrors();
        Livewire::test('pages::tokens.index')->set('name', 'three')->call('createToken')->assertHasErrors('name');

        $this->assertSame(2, AgentToken::query()->where('user_id', $visitor->id)->count());
        Notification::assertNothingSent();
    }

    public function test_tokens_of_the_demo_are_marked_for_the_tunnel_server_and_its_sample_data_is_left_out(): void
    {
        $member = User::factory()->create();
        [$real] = app(IssueAgentToken::class)->handle($member, $member->currentTeam, 'real');
        $visitor = $this->visitor();
        [$demo] = app(IssueAgentToken::class)->handle($visitor, Demo::team(), 'mine');

        $snapshot = $this->snapshot();
        $tokens = collect($snapshot['tokens'])->keyBy('id');

        $this->assertTrue($tokens[$demo->id]['demo']);
        $this->assertArrayNotHasKey('demo', $tokens[$real->id]);
        // The demo's sample addresses are reserved for it (see DemoSecurityTest); its domain and rules are left out.
        $this->assertSame(['demo-api.tunnels.example', 'demo-shop.tunnels.example'], collect($snapshot['reserved'])->pluck('hostname')->sort()->values()->all());
        $this->assertSame([], $snapshot['domains']);
        $this->assertSame([], $snapshot['teams']);
    }

    public function test_a_visitor_cannot_reach_account_settings_teams_or_the_admin_area(): void
    {
        $visitor = $this->visitor();
        $team = Demo::team();
        $dashboard = route('dashboard', ['current_team' => $team->slug]);

        foreach (['profile.edit', 'security.edit', 'appearance.edit', 'teams.index', 'admin.overview', 'password.confirm', 'verification.notice'] as $name) {
            $this->get(route($name))->assertRedirect($dashboard);
        }
        $this->get(route('teams.edit', ['team' => $team->slug]))->assertRedirect($dashboard);
        $this->get('/settings')->assertRedirect($dashboard);
        $this->postJson(route('two-factor.enable'))->assertForbidden();
        $this->postJson(route('passkey.store'))->assertForbidden();
        $this->postJson(route('verification.send'))->assertForbidden();

        $this->assertFalse($visitor->can('create', Team::class));
        Livewire::test('create-team-modal')->set('teamName', 'Mine')->call('createTeam')->assertForbidden();
        $this->assertSame(1, $visitor->teams()->count());

        $this->get($dashboard)->assertOk()->assertDontSee('New team')->assertDontSee(route('profile.edit'));
    }

    public function test_a_visitor_cannot_accept_an_invitation_into_a_real_team(): void
    {
        $owner = User::factory()->create();
        $visitor = $this->visitor();
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $owner->currentTeam->id, 'email' => $visitor->email, 'invited_by' => $owner->id,
        ]);

        $this->get(route('dashboard', ['current_team' => Demo::team()->slug]))->assertOk()->assertDontSee($owner->currentTeam->name);
        Livewire::test('pages::teams.pending-invitations-modal')
            ->assertCount('pendingInvitations', 0)
            ->call('acceptInvitation', $invitation->code)
            ->assertForbidden();
        $this->assertFalse($visitor->fresh()->belongsToTeam($owner->currentTeam));
    }

    public function test_the_reset_deletes_the_team_its_visitors_and_what_they_made_and_lays_the_samples_out_again(): void
    {
        $bystander = User::factory()->create();
        [$kept] = app(IssueAgentToken::class)->handle($bystander, $bystander->currentTeam, 'kept');
        RequestLog::factory()->forMember($bystander, $bystander->currentTeam)->create(['path' => '/kept']);

        $visitor = $this->visitor();
        $before = Demo::team();
        [$token] = app(IssueAgentToken::class)->handle($visitor, $before, 'mine');
        RequestLog::factory()->forMember($visitor, $before)->create(['path' => '/mine', 'agent_token_id' => $token->id]);

        $this->assertFalse(Demo::due());
        $this->travel(31)->minutes();
        $this->assertTrue(Demo::due());
        $this->artisan('demo:reset')->assertSuccessful();

        $after = Demo::team();
        $this->assertNotNull($after);
        $this->assertNotSame($before->id, $after->id);
        $this->assertNull(User::query()->find($visitor->id));
        $this->assertNull(AgentToken::query()->find($token->id));
        $this->assertFalse(RequestLog::query()->where('path', '/mine')->exists());
        $this->assertSame(1, Team::withTrashed()->where('is_demo', true)->count());
        $this->assertSame(1, User::query()->where('is_demo', true)->count());
        $this->assertTrue(RequestLog::query()->where('team_id', $after->id)->where('path', 'like', '/webhooks/payment%')->exists());
        $this->assertFalse(Demo::due());

        // Nothing outside the demo was touched, and the wiped token is gone from the snapshot.
        $this->assertNotNull(AgentToken::query()->find($kept->id));
        $this->assertTrue(RequestLog::query()->where('path', '/kept')->exists());
        $this->assertNotContains($token->id, collect($this->snapshot()['tokens'])->pluck('id')->all());

        // The visitor's session no longer names anyone.
        $this->app['auth']->forgetGuards();
        $this->get(route('dashboard', ['current_team' => $after->slug]))->assertRedirect(route('login'));
    }

    public function test_switching_the_demo_off_removes_it_and_signs_its_visitors_out(): void
    {
        $visitor = $this->visitor();
        $team = Demo::team();

        config(['exposehub.demo.enabled' => false]);

        $this->get(route('dashboard', ['current_team' => $team->slug]))->assertRedirect(route('home'));
        $this->assertGuest();

        $this->assertTrue(Demo::due());
        $this->artisan('demo:reset')->assertSuccessful();
        $this->assertNull(Demo::team());
        $this->assertNull(User::query()->find($visitor->id));
        $this->assertFalse(Demo::due());
    }

    public function test_the_demo_holds_a_fixed_number_of_visitors_and_one_address_starts_only_a_few(): void
    {
        // A start that finds the demo full counts against the address too.
        config(['exposehub.demo.max_visitors' => 2, 'exposehub.demo.visitors_per_ip_per_hour' => 4]);

        $this->post(route('demo.enter'))->assertRedirect();
        $this->post(route('logout'));
        $this->post(route('demo.enter'))->assertRedirect();
        $this->post(route('logout'));
        $this->post(route('demo.enter'))->assertRedirect(route('home'))->assertSessionHas('demo');
        $this->assertGuest();
        $this->assertSame(2, User::query()->where('email', 'like', 'visitor-%')->count());

        config(['exposehub.demo.max_visitors' => 50]);
        $this->post(route('demo.enter'))->assertRedirect();
        $this->post(route('logout'));
        // The fifth start from this address within the hour.
        $this->post(route('demo.enter'))->assertRedirect(route('home'))->assertSessionHas('demo');
        $this->assertSame(3, User::query()->where('email', 'like', 'visitor-%')->count());
    }

    public function test_someone_with_an_account_is_not_sent_into_the_demo(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('demo.enter'))->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, User::query()->where('email', 'like', 'visitor-%')->count());
    }

    public function test_the_admin_area_lists_visitors_apart_from_people_with_an_account(): void
    {
        config(['exposehub.admin_emails' => ['owner@example.com']]);
        $owner = User::factory()->create(['email' => 'owner@example.com', 'name' => 'Olive Owner']);
        $visitor = $this->visitor();
        $this->actingAs($owner);

        Livewire::test('pages::admin.people')
            ->assertSee('Olive Owner')
            ->assertDontSee($visitor->email)
            ->call('setFilter', 'demo')
            ->assertSee($visitor->email)
            ->assertDontSee('Olive Owner');
    }

    public function test_an_install_without_the_demo_is_unchanged(): void
    {
        config(['exposehub.demo.enabled' => false]);
        $user = User::factory()->create();

        $this->assertFalse(Demo::due());
        $this->actingAs($user)->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertDontSee('This is the demo.')
            ->assertSee('New team');
        $this->get(route('profile.edit'))->assertOk();
    }
}
