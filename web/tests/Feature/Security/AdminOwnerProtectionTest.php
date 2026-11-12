<?php

namespace Tests\Feature\Security;

use App\Actions\Admin\AdminActions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Only the mailed link makes a platform owner, and only owners act on other
 * admins, also while those are suspended.
 */
class AdminOwnerProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.admin_emails' => ['owner@example.com', 'second-owner@example.com']]);
        Notification::fake();
    }

    private function owner(): User
    {
        return User::factory()->create(['email' => 'owner@example.com']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_no_admin_can_confirm_an_owner_address_and_so_create_an_owner(): void
    {
        $squatter = User::factory()->unverified()->create(['email' => 'Second-Owner@example.com']);

        foreach ([$this->admin(), $this->owner()] as $actor) {
            Livewire::actingAs($actor)->test('pages::admin.person', ['user' => $squatter])->call('verifyEmail');

            $squatter->refresh();
            $this->assertNull($squatter->email_verified_at);
            $this->assertFalse($squatter->isOwner());
        }

        $this->assertDatabaseMissing('admin_actions', ['action' => 'user.email_verified']);
    }

    public function test_an_owner_address_is_still_confirmed_by_its_mailed_link(): void
    {
        $owner = User::factory()->unverified()->create(['email' => 'second-owner@example.com']);

        $owner->markEmailAsVerified();

        $this->assertTrue($owner->isOwner());
    }

    public function test_an_admin_can_still_suspend_an_unconfirmed_account_on_an_owner_address(): void
    {
        $squatter = User::factory()->unverified()->create(['email' => 'second-owner@example.com']);

        app(AdminActions::class)->suspend($this->admin(), $squatter, 'Not the owner');

        $this->assertTrue($squatter->refresh()->isSuspended());
    }

    public function test_other_addresses_can_still_be_confirmed_by_an_admin(): void
    {
        $person = User::factory()->unverified()->create();

        app(AdminActions::class)->verifyEmail($this->admin(), $person);

        $this->assertNotNull($person->refresh()->email_verified_at);
    }

    public function test_a_non_owner_admin_cannot_reactivate_an_admin_an_owner_suspended(): void
    {
        $owner = $this->owner();
        $other = $this->admin();
        $suspended = $this->admin();
        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $suspended])->call('suspend');
        $this->assertTrue($suspended->refresh()->isSuspended());

        Livewire::actingAs($other)->test('pages::admin.person', ['user' => $suspended])->call('reactivate');

        $this->assertTrue($suspended->refresh()->isSuspended());
        $this->assertFalse($suspended->isAdmin());
        $this->assertDatabaseMissing('admin_actions', ['action' => 'user.reactivated']);

        // The owner still can.
        Livewire::actingAs($owner)->test('pages::admin.person', ['user' => $suspended])->call('reactivate');
        $this->assertTrue($suspended->refresh()->isAdmin());
    }

    public function test_a_non_owner_admin_cannot_delete_an_admin_an_owner_suspended(): void
    {
        $owner = $this->owner();
        $other = $this->admin();
        $suspended = $this->admin();
        app(AdminActions::class)->suspend($owner, $suspended, null);

        Livewire::actingAs($other)->test('pages::admin.person', ['user' => $suspended->refresh()])
            ->set('confirmEmail', $suspended->email)->call('delete');
        $this->assertModelExists($suspended);

        // The owner still can.
        app(AdminActions::class)->delete($owner, $suspended->refresh());
        $this->assertModelMissing($suspended);
    }

    public function test_an_admin_with_an_unconfirmed_address_is_still_the_owners_to_act_on(): void
    {
        $unconfirmed = User::factory()->unverified()->create();
        $unconfirmed->forceFill(['is_admin' => true])->save();

        $this->expectException(AuthorizationException::class);

        app(AdminActions::class)->suspend($this->admin(), $unconfirmed, null);
    }
}
