<?php

use App\Http\Controllers\EnterDemo;
use App\Http\Controllers\SingleSignOn;
use App\Http\Middleware\EnsureTeamMembership;
use App\Support\LegalPage;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::view('docs', 'docs')->name('docs');

// The demo: one click signs a visitor in without an account. 404 while EXPOSEHUB_DEMO is off.
Route::post('demo', EnterDemo::class)->middleware(['guest', 'throttle:20,1'])->name('demo.enter');

// The operator's imprint and privacy notice; 404 until the operator provides the text.
// Literal paths, not a parameter, so that no team can be given one of these names.
foreach (LegalPage::cases() as $legalPage) {
    Route::get($legalPage->value, fn () => $legalPage->exists() ? view('legal', ['page' => $legalPage]) : abort(404))
        ->name('legal.'.$legalPage->value);
}

// Sign-in through the identity provider. 404 while EXPOSEHUB_SSO_* is not set.
Route::get('sso/redirect', [SingleSignOn::class, 'redirect'])->middleware('throttle:30,1')->name('sso.redirect');
Route::get('sso/callback', [SingleSignOn::class, 'callback'])->middleware('throttle:30,1')->name('sso.callback');

// The admin area. Not under a team, and named so that no admin page shares a
// path with a team page (/{team}/dashboard, inspector, logs, tokens, domains).
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'admin', 'password.confirm'])
    ->group(function () {
        Route::livewire('/', 'pages::admin.overview')->name('overview');
        Route::livewire('people', 'pages::admin.people')->name('people');
        Route::livewire('people/{user}', 'pages::admin.person')->name('person');
        Route::livewire('teams', 'pages::admin.teams')->name('teams');
        Route::livewire('tunnels', 'pages::admin.tunnels')->name('tunnels');
        Route::livewire('settings', 'pages::admin.settings')->name('settings');
        Route::livewire('audit', 'pages::admin.audit')->name('audit');
    });

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('tunnels', 'pages::tunnels.index')->name('tunnels.index');
        Route::view('inspector', 'inspector')->name('inspector');
        Route::livewire('logs', 'pages::logs.index')->name('logs.index');
        Route::livewire('tokens', 'pages::tokens.index')->name('tokens.index');
        Route::livewire('domains', 'pages::domains.index')->name('domains.index');
        Route::livewire('access', 'pages::access.index')->name('access.index');
    });

require __DIR__.'/settings.php';
