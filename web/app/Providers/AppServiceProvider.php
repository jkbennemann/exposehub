<?php

namespace App\Providers;

use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Dns\Resolver;
use App\Support\Dns\SystemResolver;
use App\Support\Domains\KubernetesIngressProvisioner;
use App\Support\Domains\NullProvisioner;
use App\Support\Domains\Provisioner;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Resolver::class, SystemResolver::class);

        $this->app->singleton(Provisioner::class, fn () => match (config('exposehub.domain_provisioner.driver')) {
            'kubernetes' => new KubernetesIngressProvisioner(config('exposehub.domain_provisioner.kubernetes')),
            default => new NullProvisioner,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($proxies = config('exposehub.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Settings changed in the admin area take precedence over config.
        Settings::apply();

        $this->configureDefaults();
        $this->sendAccountNotices();
    }

    /**
     * Mail about changes that happen in Fortify and the passkey package, where
     * an event is the only place to hear about them. Changes made in this
     * application's own code send their notice where they happen.
     */
    protected function sendAccountNotices(): void
    {
        Event::listen(PasswordReset::class, fn (PasswordReset $event) => AccountMail::send($event->user, AccountNotice::passwordChanged(viaReset: true)));
        Event::listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $event) => AccountMail::send($event->user, AccountNotice::twoFactorEnabled()));
        Event::listen(PasskeyRegistered::class, fn (PasskeyRegistered $event) => AccountMail::send($event->user, AccountNotice::passkeyAdded($event->passkey->name)));
        Event::listen(PasskeyDeleted::class, fn (PasskeyDeleted $event) => AccountMail::send($event->user, AccountNotice::passkeyRemoved($event->passkey->name)));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
