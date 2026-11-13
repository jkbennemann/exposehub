<?php

namespace App\Support\Sso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Talks OpenID Connect to an identity provider: where to send a person to
 * sign in, and what the provider then says about them.
 *
 * Only the authorization code flow with PKCE is spoken. The id token is the
 * source of truth and is taken only when all of this holds: it is signed by
 * one of the provider's published keys with an asymmetric algorithm, it was
 * issued by the configured issuer for this client, it has not expired, and it
 * carries the nonce this sign-in started with.
 */
final class OidcClient
{
    /** Asymmetric only: with a shared-secret algorithm the client secret would be the key. */
    private const array ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'];

    /** Seconds the provider's clock and this one may differ. */
    private const int LEEWAY = 60;

    private const int CACHE_SECONDS = 3600;

    private const string FAILED = 'Signing in did not work. Try again, and tell the people who run this service if it keeps failing.';

    /**
     * Where to send the person. $fresh asks the provider to make them prove
     * who they are again, even if it still knows them.
     */
    public function authorizationUrl(Connection $connection, string $state, string $nonce, string $verifier, bool $fresh = false): string
    {
        $endpoint = $this->discovery($connection)['authorization_endpoint'];
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $connection->clientId,
            'redirect_uri' => Sso::redirectUri(),
            'scope' => $connection->scopes,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ] + ($fresh ? ['prompt' => 'login', 'max_age' => 0] : []), '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /**
     * Trade the code the provider sent back for what it says about the person.
     *
     * @throws SsoException
     */
    public function claimsFor(Connection $connection, string $code, string $verifier, string $nonce): Claims
    {
        $discovery = $this->discovery($connection);
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => Sso::redirectUri(),
            'code_verifier' => $verifier,
        ];
        $request = $this->http()->asForm();
        $methods = $discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        if (is_array($methods) && ! in_array('client_secret_basic', $methods, true) && in_array('client_secret_post', $methods, true)) {
            $form += ['client_id' => $connection->clientId, 'client_secret' => $connection->clientSecret];
        } else {
            // Both are form-encoded before they go into the header (RFC 6749, 2.3.1).
            $request = $request->withBasicAuth(urlencode($connection->clientId), urlencode($connection->clientSecret));
        }

        try {
            $response = $request->post($discovery['token_endpoint'], $form);
        } catch (Throwable $e) {
            throw new SsoException(__(self::FAILED), 'token endpoint unreachable: '.$e->getMessage());
        }
        $idToken = $response->json('id_token');
        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            $error = $response->json('error');
            throw new SsoException(__(self::FAILED), 'token endpoint answered '.$response->status().(is_string($error) ? ' '.Str::limit($error, 80) : ''));
        }

        $token = $this->verified($connection, $idToken, $nonce);
        $claims = $this->claims($token);

        // Some providers keep the id token short and say the rest at the
        // userinfo endpoint: the address, or whether it is confirmed.
        $incomplete = $claims->email === null || ! property_exists($token, 'email_verified');
        if ($incomplete && is_string($response->json('access_token')) && isset($discovery['userinfo_endpoint'])) {
            try {
                $claims = $this->withUserinfo($claims, $discovery['userinfo_endpoint'], $response->json('access_token'));
            } catch (SsoException $e) {
                // Without an address there is nothing to go on. With one, the
                // token's own word stands, which is that it is not confirmed.
                if ($claims->email === null) {
                    throw $e;
                }
            }
        }

        return $claims;
    }

    /**
     * The payload of an id token, once everything about it checks out.
     *
     * @throws SsoException
     */
    public function verified(Connection $connection, string $idToken, string $nonce): stdClass
    {
        $parts = explode('.', $idToken);
        $header = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true) : null;
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;
        $kid = is_array($header) ? ($header['kid'] ?? null) : null;
        if (! is_string($alg) || ! in_array($alg, self::ALGORITHMS, true)) {
            throw new SsoException(__(self::FAILED), 'id token signed with '.(is_string($alg) ? Str::limit($alg, 20) : 'no algorithm'));
        }

        $keys = $this->keys($connection);
        if (is_string($kid) && ! isset($keys[$kid])) {
            // The provider may have changed its keys since they were last fetched.
            $keys = $this->keys($connection, refresh: true);
        }

        // The library takes its clock and its tolerance from static properties;
        // both go back to what they were, whatever else in the process uses it.
        [$leeway, $timestamp] = [JWT::$leeway, JWT::$timestamp];
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = now()->getTimestamp();
        try {
            // A provider with a single key may leave the key id out.
            $token = JWT::decode($idToken, ! is_string($kid) && count($keys) === 1 ? reset($keys) : $keys);
        } catch (Throwable $e) {
            throw new SsoException(__(self::FAILED), 'id token rejected: '.$e->getMessage());
        } finally {
            [JWT::$leeway, JWT::$timestamp] = [$leeway, $timestamp];
        }

        $problem = match (true) {
            ($token->iss ?? null) !== $connection->issuer => 'issued by someone else',
            ! $this->isFor($connection, $token) => 'issued for another client',
            ! isset($token->exp) || ! is_numeric($token->exp) => 'without an expiry',
            ! isset($token->iat) || ! is_numeric($token->iat) => 'without an issue time',
            ! isset($token->nonce) || ! is_string($token->nonce) || ! hash_equals($nonce, $token->nonce) => 'for another sign-in (nonce)',
            ! isset($token->sub) || ! is_string($token->sub) || $token->sub === '' || strlen($token->sub) > 255 => 'without a usable subject',
            default => null,
        };
        if ($problem !== null) {
            throw new SsoException(__(self::FAILED), 'id token '.$problem);
        }

        return $token;
    }

    /**
     * The token names this client as its audience, and as the party it was made for.
     */
    private function isFor(Connection $connection, stdClass $token): bool
    {
        $audiences = is_array($token->aud ?? null) ? $token->aud : [$token->aud ?? null];
        if (! in_array($connection->clientId, $audiences, true)) {
            return false;
        }
        if (isset($token->azp)) {
            return $token->azp === $connection->clientId;
        }

        return count($audiences) === 1;
    }

    private function claims(stdClass $token): Claims
    {
        return new Claims(
            subject: $token->sub,
            email: $this->email($token->email ?? null),
            emailVerified: ($token->email_verified ?? false) === true || ($token->email_verified ?? false) === 'true',
            name: $this->name($token),
            authTime: isset($token->auth_time) && is_numeric($token->auth_time) ? (int) $token->auth_time : null,
        );
    }

    /**
     * @throws SsoException
     */
    private function withUserinfo(Claims $claims, string $endpoint, string $accessToken): Claims
    {
        try {
            $info = $this->http()->withToken($accessToken)->get($endpoint);
        } catch (Throwable $e) {
            throw new SsoException(__(self::FAILED), 'userinfo endpoint unreachable: '.$e->getMessage());
        }
        // An answer about anyone but the person in the id token is not taken.
        if (! $info->successful() || $info->json('sub') !== $claims->subject) {
            throw new SsoException(__(self::FAILED), 'userinfo answered '.$info->status().' or for another subject');
        }
        $data = (object) $info->json();

        // What the signed token said stands; the endpoint fills in what it left out.
        $email = $claims->email ?? $this->email($data->email ?? null);
        $confirmed = ($data->email_verified ?? false) === true || ($data->email_verified ?? false) === 'true';

        return new Claims(
            subject: $claims->subject,
            email: $email,
            emailVerified: $claims->emailVerified || ($confirmed && $email !== null && $email === $this->email($data->email ?? null)),
            name: $claims->name ?? $this->name($data),
            authTime: $claims->authTime,
        );
    }

    private function email(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 255
            ? Str::lower($value)
            : null;
    }

    private function name(stdClass $from): ?string
    {
        $name = is_string($from->name ?? null) ? $from->name : trim((is_string($from->given_name ?? null) ? $from->given_name : '').' '.(is_string($from->family_name ?? null) ? $from->family_name : ''));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        return $name === '' ? null : Str::limit($name, 250, '');
    }

    /**
     * What the provider says about itself. Taken only when it names the
     * configured issuer, and when everything it points to is as well protected
     * as the issuer's own address.
     *
     * @return array<string, mixed>
     *
     * @throws SsoException
     */
    public function discovery(Connection $connection): array
    {
        $issuer = $connection->issuer;

        return Cache::remember('exposehub.sso.discovery.'.sha1($issuer), self::CACHE_SECONDS, function () use ($issuer) {
            try {
                $response = $this->http()->get(rtrim($issuer, '/').'/.well-known/openid-configuration');
            } catch (Throwable $e) {
                throw new SsoException(__(self::FAILED), 'discovery unreachable: '.$e->getMessage());
            }
            $document = $response->json();
            if (! $response->successful() || ! is_array($document) || ($document['issuer'] ?? null) !== $issuer) {
                throw new SsoException(__(self::FAILED), 'discovery answered '.$response->status().' or names another issuer');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                if (! is_string($document[$key] ?? null) || ! $this->asSafeAs($issuer, $document[$key])) {
                    throw new SsoException(__(self::FAILED), "discovery has no usable {$key}");
                }
            }
            if (isset($document['userinfo_endpoint']) && (! is_string($document['userinfo_endpoint']) || ! $this->asSafeAs($issuer, $document['userinfo_endpoint']))) {
                unset($document['userinfo_endpoint']);
            }

            return $document;
        });
    }

    /**
     * An endpoint must use https, unless the issuer itself is plain http, as
     * a provider run on the same machine for trying things out is.
     */
    private function asSafeAs(string $issuer, string $url): bool
    {
        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || ($scheme === 'http' && Str::startsWith(Str::lower($issuer), 'http://'));
    }

    /**
     * The provider's signing keys, by key id.
     *
     * @return array<string, Key>
     *
     * @throws SsoException
     */
    private function keys(Connection $connection, bool $refresh = false): array
    {
        $cache = 'exposehub.sso.keys.'.sha1($connection->issuer);
        if ($refresh) {
            Cache::forget($cache);
        }

        $set = Cache::remember($cache, self::CACHE_SECONDS, function () use ($connection) {
            try {
                $response = $this->http()->get($this->discovery($connection)['jwks_uri']);
            } catch (Throwable $e) {
                throw new SsoException(__(self::FAILED), 'keys unreachable: '.$e->getMessage());
            }
            $keys = $response->json('keys');
            if (! $response->successful() || ! is_array($keys)) {
                throw new SsoException(__(self::FAILED), 'keys answered '.$response->status());
            }

            return $keys;
        });

        $keys = [];
        foreach ($set as $index => $jwk) {
            // Signing keys of a kind and algorithm this client accepts; a key
            // that names no algorithm is taken for the usual one of its kind.
            $default = match (true) {
                ! is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig' => null,
                ($jwk['kty'] ?? null) === 'RSA' => 'RS256',
                ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === 'P-256' => 'ES256',
                ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === 'P-384' => 'ES384',
                default => null,
            };
            if ($default === null || (isset($jwk['alg']) && ! in_array($jwk['alg'], self::ALGORITHMS, true))) {
                continue;
            }
            try {
                $key = JWK::parseKey($jwk, $default);
            } catch (Throwable) {
                continue;
            }
            if ($key !== null) {
                $keys[is_string($jwk['kid'] ?? null) ? $jwk['kid'] : (string) $index] = $key;
            }
        }
        if ($keys === []) {
            throw new SsoException(__(self::FAILED), 'the provider publishes no usable signing key');
        }

        return $keys;
    }

    private function http(): PendingRequest
    {
        // Not following redirects: the answer must come from the address that was asked.
        return Http::timeout(8)->connectTimeout(4)->acceptJson()->withoutRedirecting();
    }
}
