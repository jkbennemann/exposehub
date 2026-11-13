<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * What must not get a person in: a token that is not the provider's answer to
 * the sign-in this browser started.
 */
class TokenChecksTest extends TestCase
{
    use FakesIdentityProvider;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSso();
    }

    /**
     * Finish a sign-in with a token built by $forge from the nonce of that
     * sign-in, and expect nobody to be signed in or created.
     */
    private function assertRefused(callable $forge, string $what): void
    {
        $sent = $this->startSignIn();
        $this->nextIdToken = $forge($sent['nonce']);

        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count(), $what.' made an account');
    }

    public function test_a_token_must_be_signed_by_one_of_the_providers_keys(): void
    {
        $stranger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($stranger, $strangerPem);

        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, key: $strangerPem), 'a token signed with a key of someone else under the provider\'s key id');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, kid: 'unknown-key', key: $strangerPem), 'a token signed with an unknown key');
        $this->assertRefused(fn ($nonce) => substr($this->idToken($nonce), 0, -6).'AAAAAA', 'a token with a changed signature');
        $this->assertRefused(function ($nonce) {
            [$header, $payload, $signature] = explode('.', $this->idToken($nonce));
            $changed = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
            $changed['email'] = 'root@corp.example';

            return $header.'.'.rtrim(strtr(base64_encode(json_encode($changed)), '+/', '-_'), '=').'.'.$signature;
        }, 'a token whose claims were changed after signing');
    }

    public function test_a_token_without_a_signature_or_signed_with_a_shared_secret_is_refused(): void
    {
        $b64 = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $claims = fn ($nonce) => ['iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 'okta|ada', 'iat' => time(), 'exp' => time() + 300, 'nonce' => $nonce, 'email' => 'ada@corp.example', 'email_verified' => true];

        // Signed by hand: the library that checks tokens will not make ones this weak.
        $hmac = function (array $claims, string $secret) use ($b64) {
            $signed = $b64(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'key-1']).'.'.$b64($claims);

            return $signed.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $signed, $secret, true)), '+/', '-_'), '=');
        };

        $this->assertRefused(fn ($nonce) => $b64(['alg' => 'none', 'typ' => 'JWT']).'.'.$b64($claims($nonce)).'.', 'an unsigned token');
        // The client secret is known to the client only, but so is it to anyone who reads its configuration.
        $this->assertRefused(fn ($nonce) => $hmac($claims($nonce), 'client-secret-for-tests'), 'a token signed with the client secret');
        // The provider's public key used as a shared secret.
        $this->assertRefused(function ($nonce) use ($claims, $hmac) {
            $public = openssl_pkey_get_details(openssl_pkey_get_private($this->providerKey('key-1')))['key'];

            return $hmac($claims($nonce), $public);
        }, 'a token signed with the public key as a secret');
        $this->assertRefused(fn () => 'not-a-token', 'something that is no token');
    }

    public function test_a_token_must_be_the_providers_answer_to_this_client_and_this_sign_in(): void
    {
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['iss' => 'https://id.evil.example']), 'a token of another issuer');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['iss' => self::ISSUER.'/']), 'a token whose issuer differs by a slash');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['aud' => 'another-client']), 'a token for another client');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['aud' => [self::CLIENT, 'another-client']]), 'a token for two clients that names no party');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['aud' => [self::CLIENT, 'another-client'], 'azp' => 'another-client']), 'a token made for another party');
        $this->assertRefused(fn ($nonce) => $this->idToken('the-nonce-of-another-sign-in'), 'a token of another sign-in');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['nonce' => null]), 'a token without a nonce');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['sub' => null]), 'a token without a subject');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['sub' => '']), 'a token with an empty subject');
    }

    public function test_a_token_must_be_current(): void
    {
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['exp' => now()->subMinutes(5)->getTimestamp()]), 'an expired token');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['exp' => null]), 'a token without an expiry');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['iat' => now()->addMinutes(10)->getTimestamp()]), 'a token from the future');
        $this->assertRefused(fn ($nonce) => $this->idToken($nonce, ['iat' => null]), 'a token without an issue time');

        // A minute's difference between two clocks is no reason to refuse.
        $sent = $this->startSignIn();
        $this->nextIdToken = $this->idToken($sent['nonce'], ['iat' => now()->addSeconds(30)->getTimestamp()]);
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']));
        $this->assertAuthenticated();
    }

    public function test_a_sign_in_is_finished_once_in_the_browser_that_started_it(): void
    {
        $sent = $this->startSignIn();
        $this->nextIdToken = $this->idToken($sent['nonce']);

        // Without the state, or with another one: someone else's sign-in pushed into this browser.
        $this->get(route('sso.callback', ['code' => 'code']))->assertSessionHasErrors('email');
        $this->assertGuest();
        $sent = $this->startSignIn();
        $this->nextIdToken = $this->idToken($sent['nonce']);
        $this->get(route('sso.callback', ['state' => 'a-state-from-elsewhere', 'code' => 'code']))->assertSessionHasErrors('email');
        $this->assertGuest();
        // The failed attempt used the pending sign-in up.
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('email');
        $this->assertGuest();

        // One that took too long.
        $sent = $this->startSignIn();
        $this->nextIdToken = $this->idToken($sent['nonce'], ['exp' => now()->addHour()->getTimestamp()]);
        $this->travel(11)->minutes();
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travelBack();

        // In another browser, with nothing pending.
        $sent = $this->startSignIn();
        $this->nextIdToken = $this->idToken($sent['nonce']);
        $this->flushSession();
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_provider_must_be_the_configured_one_and_as_well_protected(): void
    {
        $this->discoveryOverrides = ['issuer' => 'https://id.evil.example'];
        $this->get(route('sso.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        Cache::flush();
        $this->discoveryOverrides = ['token_endpoint' => 'http://id.example.org/token'];
        $this->get(route('sso.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        Cache::flush();
        $this->discoveryOverrides = ['jwks_uri' => null];
        $this->get(route('sso.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
    }

    public function test_a_key_the_provider_started_using_since_the_last_look_is_fetched(): void
    {
        $this->signInThroughProvider();
        $this->post(route('logout'));
        $fetches = $this->keySetFetches;

        // The provider now signs with a new key.
        $this->providerKey('key-2');
        $sent = $this->startSignIn();
        $this->nextIdToken = $this->idToken($sent['nonce'], kid: 'key-2');
        $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code']));

        $this->assertAuthenticated();
        $this->assertSame($fetches + 1, $this->keySetFetches);
    }
}
