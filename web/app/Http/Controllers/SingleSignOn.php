<?php

namespace App\Http\Controllers;

use App\Actions\Sso\SignInThroughProvider;
use App\Models\UserIdentity;
use App\Support\Sso\OidcClient;
use App\Support\Sso\Sso;
use App\Support\Sso\SsoException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in through the identity provider (EXPOSEHUB_SSO_*), over OpenID Connect.
 */
class SingleSignOn extends Controller
{
    /** How long a person may take at the provider before the sign-in has to start over. */
    private const int PENDING_SECONDS = 600;

    /** How recent the proof at the provider must be to count as a fresh confirmation. */
    private const int FRESH_SECONDS = 300;

    /**
     * Send the person to the provider. With ?confirm=1 a signed-in person is
     * asked to prove who they are again, which stands in for typing their
     * password where the dashboard wants a recent confirmation.
     */
    public function redirect(Request $request, OidcClient $oidc): RedirectResponse
    {
        abort_unless(Sso::enabled(), 404);

        $confirming = $request->boolean('confirm') ? $request->user()?->id : null;
        $pending = [
            'state' => Str::random(48),
            'nonce' => Str::random(48),
            'verifier' => Str::random(64),
            'confirming' => $confirming,
            'at' => now()->getTimestamp(),
        ];

        try {
            $url = $oidc->authorizationUrl($pending['state'], $pending['nonce'], $pending['verifier'], fresh: $confirming !== null);
        } catch (SsoException $e) {
            return $this->failed($request, $e);
        }
        $request->session()->put('sso.pending', $pending);

        return redirect()->away($url);
    }

    /**
     * The person comes back from the provider.
     */
    public function callback(Request $request, OidcClient $oidc, SignInThroughProvider $signIn): Response
    {
        abort_unless(Sso::enabled(), 404);

        // Taken out of the session at once: a sign-in can be finished one time only.
        $pending = $request->session()->pull('sso.pending');
        $state = $request->query('state');
        if (! is_array($pending) || ! is_string($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state)
            || now()->getTimestamp() - (int) ($pending['at'] ?? 0) > self::PENDING_SECONDS) {
            return $this->failed($request, new SsoException(__('That sign-in was started in another window or took too long. Start it again.')));
        }

        try {
            if ($request->query('error') !== null) {
                $code = is_string($request->query('error')) ? preg_replace('/[^a-z_]/', '', Str::limit($request->query('error'), 40, '')) : '';
                throw new SsoException(__(':name did not sign you in.', ['name' => Str::ucfirst(Sso::name())]), "the provider answered error={$code}");
            }
            $code = $request->query('code');
            if (! is_string($code) || $code === '') {
                throw new SsoException(__(':name did not sign you in.', ['name' => Str::ucfirst(Sso::name())]), 'no code came back');
            }
            $claims = $oidc->claimsFor($code, (string) $pending['verifier'], (string) $pending['nonce']);
            $fresh = $claims->authTime !== null && abs(now()->getTimestamp() - $claims->authTime) <= self::FRESH_SECONDS;

            if ($pending['confirming'] !== null) {
                return $this->confirmed($request, (int) $pending['confirming'], $claims->subject, $fresh);
            }

            $user = $signIn->handle($claims);
        } catch (SsoException $e) {
            return $this->failed($request, $e);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        // What RequireSsoSignIn looks for: this session began at the provider.
        // Signing in is not a confirmation, as it is not with a password: the
        // admin area and the security page ask again.
        $request->session()->put('sso.signed_in', now()->getTimestamp());

        return app(LoginResponse::class)->toResponse($request);
    }

    /**
     * A signed-in person proved again who they are: that stands for a
     * password confirmation, if it is the same person and the proof is new.
     */
    private function confirmed(Request $request, int $userId, string $subject, bool $fresh): Response
    {
        $user = $request->user();
        $same = $user !== null && $user->id === $userId
            && UserIdentity::query()->where('user_id', $userId)->where('issuer', Sso::issuer())->where('subject', $subject)->exists();

        if (! $same || ! $fresh) {
            return redirect()->route('password.confirm')->withErrors([
                'password' => $same
                    ? __(':name did not ask you to sign in again, so this cannot count as a confirmation.', ['name' => Str::ucfirst(Sso::name())])
                    : __('You confirmed as a different person than the one signed in here.'),
            ]);
        }
        $request->session()->put('auth.password_confirmed_at', now()->getTimestamp());

        return redirect()->intended(route('home'));
    }

    private function failed(Request $request, SsoException $e): RedirectResponse
    {
        if ($e->detail !== null) {
            Log::warning('Single sign-on failed: '.$e->detail);
        }

        return $request->user() !== null
            ? redirect()->route('password.confirm')->withErrors(['password' => $e->getMessage()])
            : redirect()->route('login')->withErrors(['email' => $e->getMessage()]);
    }
}
