<?php

namespace Tests\Feature\Sso;

use App\Actions\Tokens\IssueAgentToken;
use App\Models\AgentToken;
use App\Models\User;
use App\Models\UserIdentity;
use App\Notifications\Account\AccountNotice;
use App\Support\Demo;
use App\Support\Sso\Sso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Who has to sign in through the provider, what that closes, and how long
 * their agent tokens keep working without it.
 */
class EnforcementTest extends TestCase
{
    use FakesIdentityProvider;
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSso(['exposehub.node_secret' => self::SECRET, 'exposehub.admin_emails' => ['owner@corp.example']]);
    }

    private function token(User $user): AgentToken
    {
        return app(IssueAgentToken::class)->handle($user, $user->currentTeam, 'laptop')[0];
    }

    /**
     * @return array<int, int>
     */
    private function tokensTheServerAccepts(): array
    {
        return collect($this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk()->json('tokens'))->pluck('id')->sort()->values()->all();
    }

    public function test_an_account_of_the_providers_domain_cannot_get_in_with_its_password(): void
    {
        $member = User::factory()->create(['email' => 'grace@corp.example']);

        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password']);
        $this->get(route('dashboard', ['current_team' => $member->currentTeam->slug]))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your account signs in with Okta. Use the button for it.');
        $this->assertGuest();

        // A wrong password learns nothing about that.
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])->assertSessionHasErrors('email')->assertSessionMissing('status');
    }

    public function test_a_session_that_did_not_begin_at_the_provider_ends_whatever_way_it_began(): void
    {
        $member = User::factory()->create(['email' => 'grace@corp.example']);
        $team = $member->currentTeam;

        // A passkey, a remembered sign-in, a session from before single sign-on was switched on.
        $this->actingAs($member)->get(route('tokens.index', ['current_team' => $team->slug]))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($member)->getJson(route('tokens.index', ['current_team' => $team->slug]))->assertForbidden();
        $this->assertGuest();
    }

    public function test_the_people_who_run_the_install_and_accounts_of_other_domains_keep_their_password(): void
    {
        $owner = User::factory()->create(['email' => 'owner@corp.example']);
        $outside = User::factory()->create(['email' => 'freelancer@elsewhere.example']);

        foreach ([$owner, $outside] as $user) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
            $this->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))->assertOk();
            $this->post(route('logout'));
        }

        // A visitor of the demo is nobody's employee either.
        config(['exposehub.demo.enabled' => true]);
        $this->actingAs(Demo::admit())->get(route('dashboard', ['current_team' => Demo::team()->slug]))->assertOk();
    }

    public function test_nobody_registers_or_adopts_an_address_of_the_providers_domain(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Mallory', 'email' => 'ada@corp.example', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
        ])->assertSessionHasErrors(['email' => 'Accounts with an address of this domain are made by signing in with Okta.']);
        $this->assertFalse(User::query()->where('email', 'ada@corp.example')->exists());

        $outside = User::factory()->create(['email' => 'mallory@elsewhere.example']);
        $this->actingAs($outside);
        Livewire::test('pages::settings.profile')->set('email', 'ada@corp.example')->call('updateProfileInformation')->assertHasErrors('email');
        $this->assertSame('mallory@elsewhere.example', $outside->fresh()->email);

        // The owners' own addresses can be registered: they are the way in when the provider is down.
        $this->post(route('logout'));
        $this->post(route('register.store'), [
            'name' => 'Olive', 'email' => 'owner@corp.example', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_account_that_signs_in_through_the_provider_keeps_the_address_it_has_there(): void
    {
        $this->signInThroughProvider();
        $user = User::query()->sole();

        Livewire::test('pages::settings.profile')->set('email', 'ada@elsewhere.example')->call('updateProfileInformation')->assertHasErrors('email');
        Livewire::test('pages::settings.profile')->set('name', 'Ada K.')->call('updateProfileInformation')->assertHasNoErrors();

        $this->assertSame(['ada@corp.example', 'Ada K.'], [$user->fresh()->email, $user->fresh()->name]);
    }

    public function test_tokens_work_for_a_set_time_after_the_last_sign_in_through_the_provider(): void
    {
        $this->signInThroughProvider();
        $ada = User::query()->sole();
        $token = $this->token($ada);
        $outside = $this->token(User::factory()->create(['email' => 'freelancer@elsewhere.example']));

        $this->assertSame([$token->id, $outside->id], $this->tokensTheServerAccepts());
        Livewire::test('pages::tokens.index')->assertSee('Your tokens work for 30 days after your last sign-in with Okta');

        $this->travel(29)->days();
        $this->assertSame([$token->id, $outside->id], $this->tokensTheServerAccepts());

        $this->travel(2)->days();
        $this->assertSame([$outside->id], $this->tokensTheServerAccepts());
        $this->assertFalse(AgentToken::query()->usable()->whereKey($token->id)->exists());

        // Signing in again is all it takes.
        $this->signInThroughProvider();
        $this->assertSame([$token->id, $outside->id], $this->tokensTheServerAccepts());
    }

    public function test_accounts_that_never_signed_in_through_the_provider_get_the_same_time_from_when_it_was_switched_on(): void
    {
        $member = $this->token(User::factory()->create(['email' => 'grace@corp.example']));
        $owner = $this->token(User::factory()->create(['email' => 'owner@corp.example']));
        $outside = $this->token(User::factory()->create(['email' => 'freelancer@elsewhere.example']));
        // A domain that only ends like the provider's.
        $lookalike = $this->token(User::factory()->create(['email' => 'eve@notcorp.example']));
        $everyone = [$member->id, $owner->id, $outside->id, $lookalike->id];

        // Switched on today: nobody's tunnel closes for it.
        $this->assertSame($everyone, $this->tokensTheServerAccepts());
        $this->assertTrue(Sso::since()->isToday());

        $this->travel(29)->days();
        $this->assertSame($everyone, $this->tokensTheServerAccepts());

        // After that an account the provider answers for needs to have signed in through it.
        $this->travel(2)->days();
        $this->assertSame([$owner->id, $outside->id, $lookalike->id], $this->tokensTheServerAccepts());

        // Without a limit, or with single sign-on off, tokens work as before.
        config(['exposehub.sso.token_days' => 0]);
        $this->assertSame($everyone, $this->tokensTheServerAccepts());
        config(['exposehub.sso.token_days' => 30, 'exposehub.sso.issuer' => '']);
        $this->assertSame($everyone, $this->tokensTheServerAccepts());
    }

    public function test_people_are_told_once_before_their_tokens_stop_working(): void
    {
        Notification::fake();
        $this->signInThroughProvider();
        $ada = User::query()->sole();
        $this->token($ada);
        // Someone without a token has nothing to lose.
        $this->post(route('logout'));
        $this->signInThroughProvider(['sub' => 'okta|grace', 'email' => 'grace@corp.example']);

        $warnings = fn () => Notification::sent($ada, AccountNotice::class, fn (AccountNotice $notice) => $notice->kind === 'tokens-expiring')->count();
        $grace = User::query()->where('email', 'grace@corp.example')->sole();

        $this->travel(20)->days();
        $this->artisan('sso:remind')->assertSuccessful();
        $this->assertSame(0, $warnings());

        $this->travel(8)->days();
        $this->artisan('sso:remind')->assertSuccessful();
        $this->artisan('sso:remind')->assertSuccessful();
        $this->assertSame(1, $warnings());
        Notification::assertNothingSentTo($grace);

        // After the next sign-in the next warning is due again in its time.
        $this->post(route('logout'));
        $this->signInThroughProvider();
        $this->assertNull(UserIdentity::query()->where('user_id', $ada->id)->sole()->reminded_at);
    }

    public function test_a_recent_confirmation_can_be_given_at_the_provider_in_place_of_a_password(): void
    {
        $this->signInThroughProvider();
        $user = User::query()->sole();
        // Signing in is no confirmation, however recent: as with a password.
        $this->assertNull(session('auth.password_confirmed_at'));
        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
        $this->travel(4)->hours();

        $this->get(route('password.confirm'))->assertOk()->assertSee('Confirm with Okta');
        $sent = $this->startSignIn(['confirm' => 1]);
        $this->assertSame(['login', '0'], [$sent['prompt'], $sent['max_age']]);

        // Signed in again a moment ago, as the same person: confirmed.
        $this->nextIdToken = $this->idToken($sent['nonce']);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasNoErrors();
        $this->assertSame(now()->getTimestamp(), session('auth.password_confirmed_at'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_confirmation_needs_the_same_person_and_a_new_proof(): void
    {
        $this->signInThroughProvider();
        $user = User::query()->sole();
        $this->travel(4)->hours();
        session()->forget('auth.password_confirmed_at');

        // The provider did not ask again: the proof is as old as the session.
        $sent = $this->startSignIn(['confirm' => 1]);
        $this->nextIdToken = $this->idToken($sent['nonce'], ['auth_time' => now()->subHours(4)->getTimestamp()]);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertRedirect(route('password.confirm'))->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        // It says nothing about when.
        $sent = $this->startSignIn(['confirm' => 1]);
        $this->nextIdToken = $this->idToken($sent['nonce'], ['auth_time' => null]);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        // A colleague signs in at the provider on this machine: that confirms nothing, and changes nobody.
        $sent = $this->startSignIn(['confirm' => 1]);
        $this->nextIdToken = $this->idToken($sent['nonce'], ['sub' => 'okta|grace', 'email' => 'grace@corp.example']);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
    }
}
