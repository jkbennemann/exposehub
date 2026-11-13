<?php

namespace Tests\Feature\Sso;

use App\Actions\Admin\AdminActions;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a security review of single sign-on found before its first release,
 * and the attacks it tried that held. Each test here failed, or was missing,
 * then.
 */
class ReviewTest extends TestCase
{
    use FakesIdentityProvider;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSso(['exposehub.admin_emails' => ['owner@corp.example']]);
    }

    public function test_a_password_confirmation_of_one_account_is_not_inherited_by_the_next_to_sign_in(): void
    {
        // Ada, an admin, who signs in through the provider.
        $this->signInThroughProvider();
        $ada = User::query()->sole();
        $ada->forceFill(['is_admin' => true])->save();
        $this->post(route('logout'));

        // Mallory has a password account, and Ada's machine, where the provider still knows Ada.
        $mallory = User::factory()->create(['email' => 'mallory@elsewhere.example']);
        $this->post(route('login.store'), ['email' => $mallory->email, 'password' => 'password']);
        $this->post(route('password.confirm.store'), ['password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNotNull(session('auth.password_confirmed_at'));
        session(['url.intended' => 'https://exposehub.test/somewhere-mallory-was-headed']);

        // No prompt at the provider: it answers with its old proof of Ada.
        $response = $this->signInThroughProvider(['auth_time' => now()->subHours(4)->getTimestamp()]);

        $this->assertAuthenticatedAs($ada);
        $this->assertNull(session('auth.password_confirmed_at'));
        $response->assertRedirect(route('dashboard', ['current_team' => $ada->currentTeam->slug]));
        $this->get(route('admin.overview'))->assertRedirect(route('password.confirm'));
        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    }

    public function test_a_visitor_still_lands_where_they_were_headed_before_signing_in(): void
    {
        $this->signInThroughProvider();
        $ada = User::query()->sole();
        $this->post(route('logout'));
        $page = route('tokens.index', ['current_team' => $ada->currentTeam->slug]);

        $this->get($page)->assertRedirect(route('login'));
        $this->signInThroughProvider()->assertRedirect($page);
    }

    public function test_the_providers_word_never_makes_a_platform_owner(): void
    {
        // An account of the provider does not move to an owner's address.
        $this->signInThroughProvider();
        $ada = User::query()->sole();
        $this->post(route('logout'));
        $this->signInThroughProvider(['email' => 'owner@corp.example']);
        $this->assertSame('ada@corp.example', $ada->fresh()->email);
        $this->assertFalse($ada->fresh()->isAdmin());
        $this->post(route('logout'));

        // Nor is one made for it, confirmed address or not.
        $this->signInThroughProvider(['sub' => 'okta|someone-new', 'email' => 'owner@corp.example'])->assertSessionHasErrors('sso');
        config(['exposehub.sso.require_verified_email' => false]);
        $this->signInThroughProvider(['sub' => 'okta|someone-new', 'email' => 'Owner@Corp.Example', 'email_verified' => false])->assertSessionHasErrors('sso');
        $this->assertGuest();
        $this->assertFalse(User::query()->whereRaw('lower(email) = ?', ['owner@corp.example'])->exists());

        // And an owner who has an account is not signed in to it by the provider.
        $owner = User::factory()->create(['email' => 'owner@corp.example']);
        $this->signInThroughProvider(['sub' => 'okta|someone-new', 'email' => 'owner@corp.example', 'email_verified' => true])->assertSessionHasErrors('sso');
        $this->assertGuest();
        $this->assertSame(0, $owner->identities()->count());

        // An account the provider knows that is later named an owner signs in with its password from then on.
        config(['exposehub.admin_emails' => ['owner@corp.example', 'ada@corp.example']]);
        $this->signInThroughProvider()->assertSessionHasErrors('sso');
        $this->assertGuest();
    }

    public function test_the_mark_of_a_sign_in_through_the_provider_is_good_for_that_account_only(): void
    {
        $grace = User::factory()->create(['email' => 'grace@corp.example']);
        $this->signInThroughProvider();
        $ada = User::query()->where('email', 'ada@corp.example')->sole();
        $this->assertSame($ada->id, session('sso.signed_in'));

        // The session lives on under another account of the provider's domains, signed in with its old password.
        $this->actingAs($grace)->get(route('dashboard', ['current_team' => $grace->currentTeam->slug]))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_admin_cannot_confirm_an_address_the_provider_answers_for(): void
    {
        $admin = User::factory()->create(['email' => 'owner@corp.example']);
        // Someone made this account and wrote a colleague's address on it.
        $planted = User::factory()->unverified()->create(['email' => 'ada@corp.example']);
        $elsewhere = User::factory()->unverified()->create(['email' => 'max@elsewhere.example']);

        try {
            app(AdminActions::class)->verifyEmail($admin, $planted);
            $this->fail('The address was confirmed.');
        } catch (AuthorizationException) {
            $this->assertNull($planted->fresh()->email_verified_at);
        }
        // So the colleague's first sign-in still finds an account that never confirmed the address.
        $this->signInThroughProvider()->assertSessionHasErrors('sso');
        $this->assertGuest();

        app(AdminActions::class)->verifyEmail($admin, $elsewhere);
        $this->assertNotNull($elsewhere->fresh()->email_verified_at);
    }

    public function test_claims_of_the_wrong_kind_get_nobody_in(): void
    {
        foreach ([
            ['sub' => ['okta|ada']], ['sub' => 0], ['aud' => true], ['iss' => [self::ISSUER]], ['nonce' => true],
            ['nbf' => now()->addHour()->getTimestamp()],
            ['email' => ['ada@corp.example']], ['email_verified' => 1], ['email_verified' => '1'],
            ['email' => 'ada@corp.example.'], ['email' => 'ada@sub.corp.example'], ['email' => 'ada@evilcorp.example'],
            ['email' => '"ada@corp.example"@evil.example'],
        ] as $claims) {
            $this->signInThroughProvider($claims)->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $this->assertSame(0, User::query()->count());

        // Upper case is the same address.
        $ada = User::factory()->create(['email' => 'ada@corp.example']);
        $this->signInThroughProvider(['email' => 'ADA@CORP.EXAMPLE']);
        $this->assertAuthenticatedAs($ada);
    }

    public function test_a_token_without_a_key_id_is_taken_only_when_the_provider_has_one_key(): void
    {
        $withoutKeyId = function (string $nonce): string {
            return JWT::encode([
                'iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'okta|ada', 'iat' => now()->getTimestamp(), 'exp' => now()->addMinutes(5)->getTimestamp(),
                'nonce' => $nonce, 'email' => 'ada@corp.example', 'email_verified' => true,
            ], $this->providerKey('key-1'), 'RS256');
        };

        $sent = $this->startSignIn();
        $this->nextIdToken = $withoutKeyId($sent['nonce']);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']));
        $this->assertAuthenticated();
        $this->post(route('logout'));

        // With two keys there is no telling which one signed it.
        $this->providerKey('key-2');
        cache()->flush();
        $sent = $this->startSignIn();
        $this->nextIdToken = $withoutKeyId($sent['nonce']);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('sso');
        $this->assertGuest();
    }

    public function test_checking_a_token_leaves_the_librarys_clock_and_tolerance_as_they_were(): void
    {
        [JWT::$leeway, JWT::$timestamp] = [0, null];

        $this->signInThroughProvider();
        $this->assertAuthenticated();
        $this->assertSame([0, null], [JWT::$leeway, JWT::$timestamp]);

        // Also when the token is refused.
        $this->post(route('logout'));
        $this->signInThroughProvider(['iss' => 'https://id.evil.example'])->assertSessionHasErrors('sso');
        $this->assertSame([0, null], [JWT::$leeway, JWT::$timestamp]);
    }
}
