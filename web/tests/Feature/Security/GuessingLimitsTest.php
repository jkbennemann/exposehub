<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\Account\AccountNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Neither the password of a signed-in account nor a two-factor code can be
 * guessed faster than sign-in allows.
 */
class GuessingLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.admin_emails' => ['owner@example.com']]);
        Notification::fake();
    }

    private function owner(): User
    {
        return User::factory()->create(['email' => 'owner@example.com', 'password' => Hash::make('Right-Pass-123!')]);
    }

    public function test_after_five_wrong_confirmations_even_the_right_password_is_refused(): void
    {
        $user = $this->owner();
        $this->actingAs($user);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/user/confirm-password', ['password' => "wrong-{$i}"])->assertUnprocessable();
        }
        $this->postJson('/user/confirm-password', ['password' => 'Right-Pass-123!'])->assertUnprocessable();

        $this->assertNull(session('auth.password_confirmed_at'));
        $this->get(route('admin.overview'))->assertRedirect(route('password.confirm'));
    }

    public function test_the_livewire_password_checks_share_the_same_budget(): void
    {
        $user = $this->owner();
        $this->actingAs($user);

        // Wrong guesses spread over the confirmation and the two pages still add up.
        $this->postJson('/user/confirm-password', ['password' => 'wrong-1'])->assertUnprocessable();
        $this->postJson('/user/confirm-password', ['password' => 'wrong-2'])->assertUnprocessable();
        Livewire::test('pages::settings.delete-user-modal')->set('password', 'wrong-3')->call('deleteUser')->assertHasErrors('password');
        Livewire::test('pages::settings.security')
            ->set('current_password', 'wrong-4')->set('password', 'New-Pass-456!')->set('password_confirmation', 'New-Pass-456!')
            ->call('updatePassword')->assertHasErrors('current_password');
        Livewire::test('pages::settings.delete-user-modal')->set('password', 'wrong-5')->call('deleteUser')->assertHasErrors('password');

        Livewire::test('pages::settings.delete-user-modal')->set('password', 'Right-Pass-123!')->call('deleteUser')->assertHasErrors('password');
        $this->assertNotNull($user->fresh());

        Livewire::test('pages::settings.security')
            ->set('current_password', 'Right-Pass-123!')->set('password', 'New-Pass-456!')->set('password_confirmation', 'New-Pass-456!')
            ->call('updatePassword')->assertHasErrors('current_password');
        $this->assertTrue(Hash::check('Right-Pass-123!', $user->fresh()->password));

        $this->postJson('/user/confirm-password', ['password' => 'Right-Pass-123!'])->assertUnprocessable();
    }

    public function test_the_right_password_still_works_below_the_limit(): void
    {
        $user = $this->owner();
        $this->actingAs($user);

        $this->postJson('/user/confirm-password', ['password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/user/confirm-password', ['password' => 'Right-Pass-123!'])->assertCreated();
    }

    public function test_two_factor_codes_have_a_daily_cap_and_wrong_runs_end_the_pending_sign_in(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        config([
            'exposehub.limits.two_factor_attempts_per_minute' => 5,
            'exposehub.limits.two_factor_attempts_per_day' => 10,
            'exposehub.limits.two_factor_failures_in_a_row' => 5,
            'exposehub.limits.password_checks_per_minute' => 100,
        ]);
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey();
        $user = User::factory()->create([
            'password' => Hash::make('Right-Pass-123!'),
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
        $signIn = fn () => $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Right-Pass-123!'])
            ->assertRedirect(route('two-factor.login'));

        // Five wrong codes in a row end the pending sign-in: the next code,
        // even a right one, is not checked until the password is entered again.
        $signIn();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('two-factor.login.store'), ['code' => '000000']);
        }
        $this->assertNull(session('login.id'));
        $this->post(route('two-factor.login.store'), ['code' => $google2fa->getCurrentOtp($secret)]);
        $this->assertGuest();

        // Five more after the password, a minute later: the daily cap of ten is used up.
        $this->travel(2)->minutes();
        $signIn();
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('two-factor.login.store'), ['code' => '000000']);
        }
        $this->post(route('two-factor.login.store'), ['code' => '000000']);
        $this->travel(2)->minutes();
        $signIn();
        $this->post(route('two-factor.login.store'), ['code' => $google2fa->getCurrentOtp($secret)])->assertTooManyRequests();
        $this->assertGuest();

        // The account hears about it once, not for every wrong code.
        Notification::assertSentToTimes($user, AccountNotice::class, 1);
    }
}
