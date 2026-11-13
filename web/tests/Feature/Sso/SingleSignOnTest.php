<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use App\Models\UserIdentity;
use App\Notifications\Account\AccountNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SingleSignOnTest extends TestCase
{
    use FakesIdentityProvider;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSso();
    }

    public function test_it_is_off_until_the_operator_sets_a_provider_up(): void
    {
        config(['exposehub.sso.issuer' => '']);

        $this->get(route('sso.redirect'))->assertNotFound();
        $this->get(route('sso.callback', ['state' => 'x', 'code' => 'y']))->assertNotFound();
        $this->get(route('login'))->assertOk()->assertDontSee('data-test="sso-button"', false);
        Http::assertNothingSent();

        // Without a domain the provider answers for, it would answer for nobody.
        config(['exposehub.sso.issuer' => self::ISSUER, 'exposehub.sso.domains' => []]);
        $this->get(route('sso.redirect'))->assertNotFound();
    }

    public function test_the_login_page_offers_the_provider_and_sends_people_there_with_a_fresh_proof_each_time(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in with Okta')->assertSee(route('sso.redirect'), false);

        $first = $this->startSignIn();
        $second = $this->startSignIn();

        $this->assertSame('code', $first['response_type']);
        $this->assertSame(self::CLIENT, $first['client_id']);
        $this->assertSame(route('sso.callback'), $first['redirect_uri']);
        $this->assertSame('openid email profile', $first['scope']);
        $this->assertSame('S256', $first['code_challenge_method']);
        $this->assertSame(43, strlen($first['code_challenge']));
        $this->assertArrayNotHasKey('prompt', $first);
        foreach (['state', 'nonce', 'code_challenge'] as $secret) {
            $this->assertGreaterThanOrEqual(40, strlen($first[$secret]));
            $this->assertNotSame($first[$secret], $second[$secret]);
        }
    }

    public function test_a_first_sign_in_makes_an_account_and_signs_the_person_in(): void
    {
        Notification::fake();

        $response = $this->signInThroughProvider();

        $user = User::query()->where('email', 'ada@corp.example')->sole();
        $response->assertRedirect(route('dashboard', ['current_team' => $user->currentTeam->slug]));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->personalTeam()->is($user->currentTeam));
        $identity = $user->identities()->sole();
        $this->assertSame([self::ISSUER, 'okta|ada'], [$identity->issuer, $identity->subject]);
        $this->assertTrue($identity->signed_in_at->isToday());
        Notification::assertNothingSent();

        // The code was traded with the verifier that belongs to the challenge, and the client proved itself.
        Http::assertSent(fn (Request $request) => $request->url() === self::ISSUER.'/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'code-from-provider'
            && $request['redirect_uri'] === route('sso.callback')
            && strlen($request['code_verifier']) >= 43
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::CLIENT.':client-secret-for-tests')));

        $this->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))->assertOk();
    }

    public function test_a_later_sign_in_finds_the_account_by_the_providers_id_and_follows_a_changed_address(): void
    {
        $this->signInThroughProvider();
        $user = User::query()->sole();
        $this->post(route('logout'));
        $this->travel(3)->days();

        $this->signInThroughProvider(['email' => 'ada.king@corp.example', 'name' => 'Ada King']);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('ada.king@corp.example', $user->fresh()->email);
        $this->assertTrue($user->identities()->sole()->signed_in_at->isToday());

        // An address outside the provider's domains, or one somebody else has, is not taken over.
        $other = User::factory()->create(['email' => 'taken@corp.example']);
        $this->post(route('logout'));
        $this->signInThroughProvider(['email' => 'taken@corp.example']);
        $this->post(route('logout'));
        $this->signInThroughProvider(['email' => 'ada@elsewhere.example']);
        $this->assertSame('ada.king@corp.example', $user->fresh()->email);
        $this->assertSame('taken@corp.example', $other->fresh()->email);
    }

    public function test_an_existing_account_that_confirmed_the_address_is_tied_to_the_person_and_told(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'Ada@Corp.Example']);

        $this->signInThroughProvider();

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::query()->count());
        $this->assertSame('okta|ada', $existing->identities()->sole()->subject);
        Notification::assertSentTo($existing, AccountNotice::class, fn (AccountNotice $notice) => $notice->kind === 'sso-linked');
    }

    public function test_an_account_someone_put_a_colleagues_address_on_is_not_handed_to_the_colleague(): void
    {
        // Never confirmed: whoever made it knows its password and may have tokens on it.
        $planted = User::factory()->unverified()->create(['email' => 'ada@corp.example']);

        $this->signInThroughProvider()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, UserIdentity::query()->count());
        $this->assertNull($planted->fresh()->email_verified_at);
    }

    public function test_an_account_already_tied_to_someone_else_at_the_provider_is_not_handed_on(): void
    {
        $this->signInThroughProvider();
        $first = User::query()->sole();
        $this->post(route('logout'));

        // The address was given to a new colleague, who has another id at the provider.
        $this->signInThroughProvider(['sub' => 'okta|ada-the-second'])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, UserIdentity::query()->count());
        $this->assertSame('okta|ada', $first->identities()->sole()->subject);
    }

    public function test_the_provider_is_believed_only_for_confirmed_addresses_of_its_own_domains(): void
    {
        $this->signInThroughProvider(['email' => 'ada@elsewhere.example'])->assertSessionHasErrors('email');
        $this->signInThroughProvider(['email_verified' => false])->assertSessionHasErrors('email');
        $this->signInThroughProvider(['email_verified' => null])->assertSessionHasErrors('email');
        $this->signInThroughProvider(['email' => null])->assertSessionHasErrors('email');
        $this->signInThroughProvider(['email' => 'ada@corp.example.evil.example'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());

        // A provider that never says whether an address is confirmed: the operator may take its word.
        config(['exposehub.sso.require_verified_email' => false]);
        $this->signInThroughProvider(['email_verified' => null]);
        $this->assertAuthenticated();
    }

    public function test_the_address_is_asked_for_at_the_userinfo_endpoint_when_the_token_leaves_it_out(): void
    {
        $this->userinfo = ['sub' => 'okta|ada', 'email' => 'ada@corp.example', 'email_verified' => true, 'name' => 'Ada Lovelace'];
        $this->signInThroughProvider(['email' => null, 'email_verified' => null, 'name' => null]);
        $this->assertAuthenticatedAs(User::query()->where('email', 'ada@corp.example')->sole());
        $this->post(route('logout'));

        // An answer about somebody else is not taken.
        $this->userinfo = ['sub' => 'okta|mallory', 'email' => 'grace@corp.example', 'email_verified' => true];
        $this->signInThroughProvider(['sub' => 'okta|grace', 'email' => null])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse(User::query()->where('email', 'grace@corp.example')->exists());
    }

    public function test_a_suspended_account_does_not_get_in(): void
    {
        $this->signInThroughProvider();
        $user = User::query()->sole();
        $this->post(route('logout'));
        $user->forceFill(['suspended_at' => now()])->save();

        $this->signInThroughProvider()->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_what_the_provider_reports_as_an_error_is_shown_without_its_text(): void
    {
        $sent = $this->startSignIn();

        $this->get(route('sso.callback', ['state' => $sent['state'], 'error' => 'access_denied', 'error_description' => '<script>alert(1)</script>']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Okta did not sign you in.']);
        $this->assertGuest();
    }
}
