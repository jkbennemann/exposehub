<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

/**
 * Someone can sign up with another person's address before that person does.
 * When the real owner resets the password, everything the earlier holder set
 * up has to stop working: their sessions, passkeys and two-factor setup.
 */
class AccountTakeoverAfterResetTest extends TestCase
{
    use RefreshDatabase;
    use ReplaysLivewireSnapshots;

    private const string EMAIL = 'owner@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        $this->browser(null);
        Notification::fake();
    }

    /**
     * Each request as a separate browser that only has the given session cookie.
     */
    private function browser(?string $sessionId): static
    {
        Auth::forgetGuards();
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->defaultCookies = [];
        $this->withCredentials();
        if ($sessionId !== null) {
            $this->withCookie(config('session.cookie'), $sessionId);
        }

        return $this;
    }

    private function sessionId(TestResponse $response): string
    {
        return $response->getCookie(config('session.cookie'), true)->getValue();
    }

    private function signUp(): string
    {
        $response = $this->browser(null)->post('/register', [
            'name' => 'Holder', 'email' => self::EMAIL,
            'password' => 'Earlier-Holder-135!', 'password_confirmation' => 'Earlier-Holder-135!',
        ]);

        return $this->sessionId($response);
    }

    private function resetPassword(User $user): void
    {
        $this->browser(null)->post('/forgot-password', ['email' => self::EMAIL]);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->browser(null)->post('/reset-password', [
            'token' => $token, 'email' => self::EMAIL,
            'password' => 'Real-Owner-864!', 'password_confirmation' => 'Real-Owner-864!',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_unverified_account_cannot_enrol_passkeys_or_two_factor(): void
    {
        $session = $this->signUp();
        $this->browser($session)->postJson('/user/confirm-password', ['password' => 'Earlier-Holder-135!'])->assertSuccessful();

        $this->browser($session)->getJson('/user/passkeys/options')->assertForbidden();
        $this->browser($session)->postJson('/user/passkeys', ['name' => 'Mine', 'credential' => []])->assertForbidden();
        $this->browser($session)->postJson('/user/two-factor-authentication')->assertForbidden();
        $this->browser($session)->postJson('/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertForbidden();

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertNull($user->two_factor_secret);

        // Once the address is confirmed, the same request works.
        $user->markEmailAsVerified();
        $this->browser($session)->getJson('/user/passkeys/options')->assertOk();
    }

    public function test_a_session_opened_before_the_reset_is_signed_out_after_it(): void
    {
        $earlier = $this->signUp();
        $this->browser($earlier)->get('/settings/profile')->assertOk();

        $this->resetPassword(User::where('email', self::EMAIL)->firstOrFail());

        $this->browser($earlier)->get('/settings/profile')->assertRedirect(route('login'));
        $this->browser($earlier)->getJson('/user/passkeys/options')->assertUnauthorized();
    }

    public function test_changing_the_password_signs_out_other_devices_but_not_this_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Old-Pass-123!')]);
        $login = fn () => $this->sessionId($this->browser(null)->post('/login', ['email' => $user->email, 'password' => 'Old-Pass-123!']));
        $other = $login();
        $mine = $login();
        $this->browser($other)->get('/settings/profile')->assertOk();

        $this->browser($mine)->postJson('/user/confirm-password', ['password' => 'Old-Pass-123!'])->assertSuccessful();
        $html = $this->browser($mine)->get(route('security.edit'))->assertOk()->getContent();
        $this->browser($mine)->replay($this->snapshotOf($html, 'pages::settings.security'), [
            'current_password' => 'Old-Pass-123!', 'password' => 'New-Pass-456!', 'password_confirmation' => 'New-Pass-456!',
        ], [['updatePassword']])->assertOk();
        $this->assertTrue(Hash::check('New-Pass-456!', $user->fresh()->password));

        $this->browser($mine)->get('/settings/profile')->assertOk();
        $this->browser($other)->get('/settings/profile')->assertRedirect(route('login'));
    }

    public function test_a_passkey_added_before_the_address_was_confirmed_is_gone_after_a_reset(): void
    {
        $session = $this->signUp();
        $user = User::where('email', self::EMAIL)->firstOrFail();

        // A passkey from before enrolment required a confirmed address: enrol
        // it while confirmed, then take the confirmation back.
        $user->markEmailAsVerified();
        $this->browser($session)->postJson('/user/confirm-password', ['password' => 'Earlier-Holder-135!'])->assertSuccessful();
        [$credentialId, $userHandle, $key] = $this->enrolPasskey($session);
        $user->forceFill(['email_verified_at' => null, 'two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();

        $this->assertSame(200, $this->signInWithPasskey($credentialId, $userHandle, $key, 1)->status());

        $this->resetPassword($user);

        $user->refresh();
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNotSame(200, $this->signInWithPasskey($credentialId, $userHandle, $key, 2)->status());
    }

    public function test_a_reset_keeps_the_passkeys_of_a_confirmed_account(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL]);
        $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'abc', 'credential' => ['id' => 'abc']]);

        $this->resetPassword($user);

        $this->assertSame(1, $user->passkeys()->count());
    }

    /**
     * @return array{string, string, \OpenSSLAsymmetricKey}
     */
    private function enrolPasskey(string $session): array
    {
        $options = $this->browser($session)->getJson('/user/passkeys/options')->assertOk();
        // The curve sets the key size; some OpenSSL builds still want bits named.
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 384]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $credentialId = random_bytes(16);
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), -3 => new CborBytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))]);
        $authData = hash('sha256', Passkeys::relyingPartyId(), true).chr(0x45).pack('N', 0).str_repeat("\0", 16)
            .pack('n', strlen($credentialId)).$credentialId.$cose;
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options->json('options.challenge'), 'origin' => Passkeys::allowedOrigins()[0], 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);

        $this->browser($session)->postJson('/user/passkeys', ['name' => 'Earlier holder', 'credential' => [
            'id' => self::b64u($credentialId), 'rawId' => self::b64u($credentialId), 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64u($clientData),
                'attestationObject' => self::b64u(self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)])),
                'transports' => ['internal'],
            ],
        ]])->assertSuccessful();

        return [$credentialId, (string) $options->json('options.user.id'), $key];
    }

    private function signInWithPasskey(string $credentialId, string $userHandle, \OpenSSLAsymmetricKey $key, int $counter): TestResponse
    {
        $session = $this->sessionId($this->browser(null)->getJson('/passkeys/login/options'));
        $options = $this->browser($session)->getJson('/passkeys/login/options');
        $authData = hash('sha256', Passkeys::relyingPartyId(), true).chr(0x05).pack('N', $counter);
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options->json('options.challenge'), 'origin' => Passkeys::allowedOrigins()[0], 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256);

        return $this->browser($session)->postJson('/passkeys/login', ['credential' => [
            'id' => self::b64u($credentialId), 'rawId' => self::b64u($credentialId), 'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64u($clientData),
                'authenticatorData' => self::b64u($authData),
                'signature' => self::b64u($signature),
                'userHandle' => $userHandle,
            ],
        ]]);
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function cborHead(int $major, int $n): string
    {
        return match (true) {
            $n < 24 => chr(($major << 5) | $n),
            $n < 256 => chr(($major << 5) | 24).chr($n),
            default => chr(($major << 5) | 25).pack('n', $n),
        };
    }

    private static function cbor(mixed $value): string
    {
        if ($value instanceof CborBytes) {
            return self::cborHead(2, strlen($value->bytes)).$value->bytes;
        }
        if (is_int($value)) {
            return $value >= 0 ? self::cborHead(0, $value) : self::cborHead(1, -1 - $value);
        }
        if (is_string($value)) {
            return self::cborHead(3, strlen($value)).$value;
        }
        $out = self::cborHead(5, count($value));
        foreach ($value as $k => $v) {
            $out .= self::cbor($k).self::cbor($v);
        }

        return $out;
    }
}

/**
 * A CBOR byte string, as opposed to a text string.
 */
final class CborBytes
{
    public function __construct(public string $bytes) {}
}
