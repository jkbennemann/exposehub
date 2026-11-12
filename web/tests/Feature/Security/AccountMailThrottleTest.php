<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * Actions that mail an address someone typed in are limited, so the service
 * cannot be used to mail anyone in bulk under its own sender.
 */
class AccountMailThrottleTest extends TestCase
{
    use RefreshDatabase;
    use ReplaysLivewireSnapshots;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function profileSnapshot(User $user): string
    {
        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();

        return $this->snapshotOf($html, 'pages::settings.profile');
    }

    public function test_verification_mail_is_resent_six_times_a_minute_at_most(): void
    {
        $user = User::factory()->unverified()->create();

        for ($i = 1; $i <= 6; $i++) {
            Livewire::actingAs($user)->test('pages::settings.profile')->call('resendVerificationNotification')->assertHasNoErrors();
        }
        Livewire::actingAs($user)->test('pages::settings.profile')->call('resendVerificationNotification')->assertHasErrors('email');

        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    }

    public function test_many_resends_in_one_request_are_bounded_too(): void
    {
        $user = User::factory()->unverified()->create();
        $snapshot = $this->profileSnapshot($user);

        $this->replay($snapshot, [], array_fill(0, 20, ['resendVerificationNotification']))->assertOk();

        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    }

    public function test_the_address_changes_three_times_an_hour_at_most(): void
    {
        $user = User::factory()->unverified()->create();

        for ($i = 1; $i <= 3; $i++) {
            Livewire::actingAs($user)->test('pages::settings.profile')
                ->set('email', "new{$i}@example.com")->call('updateProfileInformation')->assertHasNoErrors();
        }
        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('email', 'new4@example.com')->call('updateProfileInformation')->assertHasErrors('email');

        $this->assertSame('new3@example.com', $user->fresh()->email);
        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    }

    public function test_many_address_changes_in_one_request_are_bounded_too(): void
    {
        $user = User::factory()->unverified()->create();
        $snapshot = $this->profileSnapshot($user);

        // One request can carry the same component many times, each with its own address.
        $components = array_map(fn (int $i) => [
            'snapshot' => $snapshot,
            'updates' => ['email' => "batch{$i}@example.com"],
            'calls' => [['method' => 'updateProfileInformation', 'params' => [], 'metadata' => []]],
        ], range(1, 10));
        Livewire::flushState();
        $this->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => $components], ['X-Livewire' => '1']);

        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
        $this->assertSame('batch3@example.com', $user->fresh()->email);
    }

    public function test_one_network_address_signs_up_five_times_an_hour_at_most(): void
    {
        config(['exposehub.limits.registrations_per_ip_per_hour' => 5]);
        $register = fn (int $i) => $this->post(route('register.store'), [
            'name' => "Person {$i}", 'email' => "person{$i}@example.com",
            'password' => 'Long-Enough-Pass-1!', 'password_confirmation' => 'Long-Enough-Pass-1!',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $register($i)->assertSessionHasNoErrors();
            auth()->logout();
        }
        $register(6)->assertSessionHasErrors('email');

        $this->assertSame(5, User::count());
        Notification::assertSentTimes(VerifyEmail::class, 5);
    }

    public function test_password_reset_links_are_limited_per_address_and_per_network_address(): void
    {
        config([
            'exposehub.limits.password_reset_links_per_address_per_hour' => 3,
            'exposehub.limits.password_reset_links_per_ip_per_hour' => 5,
        ]);
        $user = User::factory()->create();

        for ($i = 1; $i <= 3; $i++) {
            $this->travel(2)->minutes(); // past the broker's own one-minute wait
            $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        }
        $this->travel(2)->minutes();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasErrors('email');
        Notification::assertSentToTimes($user, ResetPassword::class, 3);

        // Other addresses from the same network address: two more, then no more.
        $this->post(route('password.email'), ['email' => 'nobody1@example.com']);
        $this->post(route('password.email'), ['email' => 'nobody2@example.com']);
        $this->post(route('password.email'), ['email' => 'nobody3@example.com'])->assertSessionHasErrors('email');
    }
}
