<?php

namespace Tests\Feature\Demo;

use App\Actions\Tokens\IssueAgentToken;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Security\ReplaysLivewireSnapshots;
use Tests\TestCase;

/**
 * What a visitor of the demo must not be able to do even with requests a
 * browser would never send. Found by a security review before the demo was
 * first released.
 */
class DemoSecurityTest extends TestCase
{
    use RefreshDatabase;
    use ReplaysLivewireSnapshots;

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
     * The saved state of a component on a page the visitor may not open,
     * taken by someone with an ordinary account, as anyone can register one.
     */
    private function snapshotFromAnAccount(string $route, string $component): string
    {
        $html = $this->actingAs(User::factory()->create())->get(route($route))->assertOk()->getContent();
        $this->flushSession();

        return $this->snapshotOf($html, $component);
    }

    private function visitor(): User
    {
        $visitor = Demo::admit();
        $this->assertNotNull($visitor);
        $this->actingAs($visitor);

        return $visitor;
    }

    public function test_a_visitor_cannot_change_the_account_by_sending_back_the_profile_page_of_another_account(): void
    {
        Notification::fake();
        $snapshot = $this->snapshotFromAnAccount('profile.edit', 'pages::settings.profile');
        $visitor = $this->visitor();
        $email = $visitor->email;

        $this->replay($snapshot, ['name' => 'Mallory', 'email' => 'victim@example.org'], [['updateProfileInformation']])->assertForbidden();

        $visitor->refresh();
        $this->assertSame($email, $visitor->email);
        $this->assertSame('Demo visitor', $visitor->name);
        Notification::assertNothingSent();
    }

    public function test_a_visitor_cannot_leave_the_demo_team(): void
    {
        $snapshot = $this->snapshotFromAnAccount('teams.index', 'pages::teams.index');
        $visitor = $this->visitor();
        $team = Demo::team();

        $this->replay($snapshot, [], [['leaveTeam', [$team->id]]])->assertForbidden();
        $this->replay($snapshot, ['name' => 'Escape'], [['createTeam']])->assertForbidden();

        $this->assertTrue($visitor->fresh()->belongsToTeam($team));
        $this->assertFalse($visitor->can('leave', $team));
        $this->assertSame(0, $visitor->ownedTeams()->count());
    }

    public function test_the_pages_of_the_demo_still_answer_their_own_requests(): void
    {
        $visitor = $this->visitor();
        $team = Demo::team();
        $html = $this->get(route('tokens.index', ['current_team' => $team->slug]))->assertOk()->getContent();

        $this->replay($this->snapshotOf($html, 'pages::tokens.index'), ['name' => 'laptop'], [['createToken']])->assertOk();

        $this->assertSame(1, AgentToken::query()->where('user_id', $visitor->id)->where('name', 'laptop')->count());
    }

    public function test_every_component_a_visitor_is_shown_and_can_use_is_one_they_may_drive(): void
    {
        $this->visitor();
        $team = Demo::team();
        // Shown in the layout, but closed to a visitor by their own checks; a request to them is refused whole.
        $closed = ['create-team-modal', 'pages::teams.pending-invitations-modal'];

        foreach (['dashboard', 'tunnels.index', 'inspector', 'logs.index', 'tokens.index', 'domains.index', 'access.index'] as $page) {
            $html = $this->get(route($page, ['current_team' => $team->slug]))->assertOk()->getContent();
            preg_match_all('/wire:snapshot="([^"]+)"/', $html, $found);

            foreach ($found[1] as $raw) {
                $snapshot = htmlspecialchars_decode($raw, ENT_QUOTES | ENT_SUBSTITUTE);
                $name = json_decode($snapshot, true)['memo']['name'];
                $response = $this->replay($snapshot);

                in_array($name, $closed, true)
                    ? $response->assertForbidden()
                    : $this->assertSame(200, $response->status(), "A visitor is shown {$name} on {$page} but may not use it.");
            }
        }
    }

    public function test_no_mail_ever_goes_to_an_account_of_the_demo(): void
    {
        Notification::fake();
        $visitor = $this->visitor();

        $visitor->sendEmailVerificationNotification();
        $this->assertSame(Password::RESET_LINK_SENT, Password::sendResetLink(['email' => $visitor->email]));

        Notification::assertNotSentTo($visitor, VerifyEmail::class);
        Notification::assertNotSentTo($visitor, ResetPassword::class);
        Notification::assertNothingSent();
    }

    public function test_nobody_can_sign_in_to_the_keeper_of_the_sample_data(): void
    {
        Demo::reset();
        $keeper = User::query()->where('email', 'sample@demo.invalid')->sole();

        foreach (['', 'password', 'sample'] as $guess) {
            $this->post(route('login.store'), ['email' => $keeper->email, 'password' => $guess]);
            $this->assertGuest();
        }
    }

    public function test_tokens_of_a_demo_that_was_switched_off_stop_working_at_once(): void
    {
        $member = User::factory()->create();
        [$real] = app(IssueAgentToken::class)->handle($member, $member->currentTeam, 'real');
        [$demo] = app(IssueAgentToken::class)->handle($this->visitor(), Demo::team(), 'laptop');

        config(['exposehub.demo.enabled' => false]);

        $ids = collect($this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk()->json('tokens'))->pluck('id')->all();
        $this->assertNotContains($demo->id, $ids);
        $this->assertContains($real->id, $ids);
    }

    public function test_the_addresses_the_demo_shows_are_kept_from_everyone(): void
    {
        Demo::reset();
        $team = Demo::team();

        $reserved = collect($this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk()->json('reserved'));

        // Reserved for the demo team, whose own agents cannot pick a name: so nobody serves a page there.
        $this->assertEqualsCanonicalizing(['demo-api.tunnels.example', 'demo-shop.tunnels.example'], $reserved->pluck('hostname')->all());
        $this->assertSame([$team->id], $reserved->pluck('team_id')->unique()->values()->all());
    }

    public function test_addresses_of_one_ipv6_network_count_as_one_visitor(): void
    {
        config(['exposehub.demo.visitors_per_ip_per_hour' => 2]);

        foreach (['2001:db8:1:2::1', '2001:db8:1:2:aaaa::7'] as $address) {
            $this->withServerVariables(['REMOTE_ADDR' => $address])->post(route('demo.enter'))->assertRedirect();
            $this->post(route('logout'));
        }
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff::9'])->post(route('demo.enter'))
            ->assertRedirect(route('home'))->assertSessionHas('demo');
        $this->assertGuest();

        // Another network is another visitor.
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])->post(route('demo.enter'));
        $this->assertAuthenticated();
        $this->assertSame(3, User::query()->where('email', 'like', 'visitor-%')->count());
    }

    public function test_an_admin_can_act_on_a_visitor_without_making_them_more_than_one(): void
    {
        config(['exposehub.admin_emails' => ['owner@example.com']]);
        $visitor = $this->visitor();
        $visitor->forceFill(['is_admin' => true])->save();

        $this->assertFalse($visitor->fresh()->isAdmin());
        $this->actingAs($visitor->fresh())->get(route('admin.overview'))
            ->assertRedirect(route('dashboard', ['current_team' => Demo::team()->slug]));
        $this->assertSame(1, Team::query()->where('is_demo', true)->count());
    }
}
