<?php

use App\Http\Middleware\AuthenticateTunnelNode;
use App\Http\Middleware\DenyFraming;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureVerifiedForAccountSecurity;
use App\Http\Middleware\LimitDemoVisitors;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Middleware\RequireSsoSignIn;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\ThrottlePasswordResetLinks;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::prefix('internal/v1')
                ->middleware(AuthenticateTunnelNode::class)
                ->group(__DIR__.'/../routes/internal.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Ends every other session of an account once its password changes.
            AuthenticateSession::class,
            EnsureNotSuspended::class,
            LimitDemoVisitors::class,
            RequireSsoSignIn::class,
            EnsureVerifiedForAccountSecurity::class,
            ThrottlePasswordResetLinks::class,
            SetTeamUrlDefaults::class,
        ]);

        // Global, not only the web group: also covers pages for unknown URLs.
        $middleware->append(DenyFraming::class);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'password.confirm' => RequireRecentPassword::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'internal/*') || $request->expectsJson(),
        );
    })->create();
