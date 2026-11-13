<?php

namespace Tests\Feature\Sso;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * An identity provider for tests: it publishes a discovery document and a
 * signing key, and hands out whatever id token a test asks for, signed for
 * real. Sign-ins go through the application's own routes.
 */
trait FakesIdentityProvider
{
    protected const string ISSUER = 'https://id.example.org';

    protected const string CLIENT = 'exposehub-client';

    /** @var array<string, string> key id => private key (PEM) */
    protected array $providerKeys = [];

    /** What the token endpoint answers with next. */
    protected ?string $nextIdToken = null;

    /** @var array<string, mixed>|null */
    protected ?array $userinfo = null;

    /** @var array<string, mixed> */
    protected array $discoveryOverrides = [];

    /** Key ids left out of the published set until the set is asked for a second time. */
    protected array $keysPublishedLate = [];

    protected int $keySetFetches = 0;

    protected function setUpSso(array $config = []): void
    {
        config([
            'exposehub.sso.issuer' => self::ISSUER,
            'exposehub.sso.client_id' => self::CLIENT,
            'exposehub.sso.client_secret' => 'client-secret-for-tests',
            'exposehub.sso.name' => 'Okta',
            'exposehub.sso.domains' => ['corp.example'],
            'exposehub.sso.token_days' => 30,
            'exposehub.sso.require_verified_email' => true,
            ...$config,
        ]);
        Cache::flush();
        $this->providerKey('key-1');

        Http::fake(function (Request $request) {
            return match (true) {
                $request->url() === self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                    'issuer' => self::ISSUER,
                    'authorization_endpoint' => self::ISSUER.'/authorize',
                    'token_endpoint' => self::ISSUER.'/token',
                    'jwks_uri' => self::ISSUER.'/keys',
                    'userinfo_endpoint' => self::ISSUER.'/userinfo',
                    ...$this->discoveryOverrides,
                ]),
                $request->url() === self::ISSUER.'/keys' => Http::response(['keys' => $this->publishedKeys()]),
                $request->url() === self::ISSUER.'/token' => $this->nextIdToken === null
                    ? Http::response(['error' => 'invalid_grant'], 400)
                    : Http::response(['id_token' => $this->nextIdToken, 'access_token' => 'access-token', 'token_type' => 'Bearer']),
                $request->url() === self::ISSUER.'/userinfo' => $this->userinfo === null ? Http::response([], 401) : Http::response($this->userinfo),
                default => Http::response('unexpected request to '.$request->url(), 500),
            };
        });
    }

    /**
     * Make a signing key, or return the one made before.
     */
    protected function providerKey(string $kid): string
    {
        if (! isset($this->providerKeys[$kid])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            $this->providerKeys[$kid] = $pem;
        }

        return $this->providerKeys[$kid];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function publishedKeys(): array
    {
        $this->keySetFetches++;
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $keys = [];
        foreach ($this->providerKeys as $kid => $pem) {
            if ($this->keySetFetches === 1 && in_array($kid, $this->keysPublishedLate, true)) {
                continue;
            }
            $details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
            $keys[] = ['kty' => 'RSA', 'use' => 'sig', 'kid' => $kid, 'alg' => 'RS256', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
        }

        return $keys;
    }

    /**
     * An id token as the provider would issue it for the sign-in that is
     * under way, with $claims laid over the usual ones. A claim set to null
     * is left out.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function idToken(string $nonce, array $claims = [], string $kid = 'key-1', string $alg = 'RS256', ?string $key = null): string
    {
        $payload = array_filter([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT,
            'sub' => 'okta|ada',
            'iat' => now()->getTimestamp(),
            'exp' => now()->addMinutes(5)->getTimestamp(),
            'auth_time' => now()->getTimestamp(),
            'nonce' => $nonce,
            'email' => 'ada@corp.example',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
            ...$claims,
        ], fn ($value) => $value !== null);

        return JWT::encode($payload, $key ?? $this->providerKey($kid), $alg, $kid);
    }

    /**
     * Start a sign-in and return what the application sent the browser to
     * the provider with.
     *
     * @return array<string, string>
     */
    protected function startSignIn(array $query = []): array
    {
        $response = $this->get(route('sso.redirect', $query));
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $sent);

        return $sent;
    }

    /**
     * A whole sign-in: to the provider and back, with the id token the
     * provider would issue for $claims.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function signInThroughProvider(array $claims = [], array $query = []): TestResponse
    {
        $sent = $this->startSignIn($query);
        $this->nextIdToken = $this->idToken($sent['nonce'], $claims);

        return $this->get(route('sso.callback', ['state' => $sent['state'], 'code' => 'code-from-provider']));
    }
}
