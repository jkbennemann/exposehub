<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\PasswordAttempts;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Livewire\Livewire;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->limitTwoFactorFailures();
        $this->configureLivewireMiddleware();
    }

    /**
     * Re-run the access checks of a page on every Livewire request it sends.
     *
     * A Livewire update goes to one shared URL, not to the page's route, so
     * only the middleware listed here is applied again. Without it, a saved
     * snapshot keeps working after its holder lost the admin role, left the
     * team, or let the password confirmation expire.
     */
    private function configureLivewireMiddleware(): void
    {
        Livewire::addPersistentMiddleware([
            RequirePassword::class,
            RequireRecentPassword::class,
            EnsureEmailIsVerified::class,
            EnsureAdmin::class,
            EnsureTeamMembership::class,
        ]);
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        // Wrong passwords count against the account's budget, shared with the
        // other checks of the current password.
        Fortify::confirmPasswordsUsing(fn (User $user, ?string $password) => PasswordAttempts::check($user, $password));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => view('pages::auth.login', [
            'teamInvitation' => $this->teamInvitation($request),
        ]));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn (Request $request) => view('pages::auth.register', [
            'teamInvitation' => $this->teamInvitation($request),
        ]));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * After several wrong two-factor codes in a row the pending sign-in ends,
     * so the password has to pass the sign-in limiter again, and the account
     * hears about it once a day at most: whoever is guessing knows the password.
     */
    private function limitTwoFactorFailures(): void
    {
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, function (ValidTwoFactorAuthenticationCodeProvided $event) {
            RateLimiter::clear('two-factor-failures:'.$event->user->getKey());
        });

        Event::listen(TwoFactorAuthenticationFailed::class, function (TwoFactorAuthenticationFailed $event) {
            $user = $event->user;
            $key = 'two-factor-failures:'.$user->getKey();
            $failures = RateLimiter::hit($key, 86400);

            if ($failures < (int) config('exposehub.limits.two_factor_failures_in_a_row')) {
                return;
            }

            RateLimiter::clear($key);
            session()->forget(['login.id', 'login.remember']);

            if (Cache::add('two-factor-failures-notice:'.$user->getKey(), true, now()->addDay())) {
                AccountMail::send($user, AccountNotice::twoFactorFailures($failures));
            }
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        // Codes for one pending sign-in: a few a minute, and a cap per day so
        // the six digits cannot be worked through over weeks.
        RateLimiter::for('two-factor', function (Request $request) {
            $pending = (string) $request->session()->get('login.id');

            return [
                Limit::perMinute((int) config('exposehub.limits.two_factor_attempts_per_minute'))->by('minute:'.$pending),
                Limit::perDay((int) config('exposehub.limits.two_factor_attempts_per_day'))->by('day:'.$pending),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Fortify's register route takes no limiter, so CreateNewUser applies this one.
        RateLimiter::for('register', fn (Request $request) => Limit::perHour((int) config('exposehub.limits.registrations_per_ip_per_hour'))
            ->by((string) $request->ip()));

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }

    /**
     * Get the pending team invitation context for auth pages.
     *
     * @return array{code: string, teamName: string}|null
     */
    private function teamInvitation(Request $request): ?array
    {
        $invitationCode = $request->query('invitation');

        if (! is_string($invitationCode)) {
            return null;
        }

        $invitation = TeamInvitation::query()
            ->with('team')
            ->where('code', $invitationCode)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();

        if (! $invitation) {
            return null;
        }

        return [
            'code' => $invitation->code,
            'teamName' => $invitation->team->name,
        ];
    }
}
