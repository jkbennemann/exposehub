<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllowedEmailDomainTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email): TestResponse
    {
        return $this->post(route('register.store'), [
            'name' => 'New Person',
            'email' => $email,
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ]);
    }

    public function test_anyone_can_register_while_no_domains_are_configured(): void
    {
        config(['exposehub.allowed_email_domains' => []]);

        $this->register('someone@anywhere.example')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'someone@anywhere.example']);
    }

    #[DataProvider('allowedAddresses')]
    public function test_addresses_on_an_allowed_domain_can_register(string $email): void
    {
        config(['exposehub.allowed_email_domains' => ['company.example', '@Private.example ']]);

        $this->register($email)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedAddresses(): array
    {
        return [
            'first domain' => ['new@company.example'],
            'second domain' => ['new@private.example'],
            'upper case' => ['New@COMPANY.example'],
        ];
    }

    #[DataProvider('refusedAddresses')]
    public function test_other_addresses_cannot_register(string $email): void
    {
        config(['exposehub.allowed_email_domains' => ['company.example', 'private.example']]);

        $this->register($email)->assertSessionHasErrors([
            'email' => 'Accounts are limited to @company.example and @private.example addresses.',
        ]);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedAddresses(): array
    {
        return [
            'another domain' => ['new@example.com'],
            'a subdomain' => ['new@mail.company.example'],
            'a lookalike suffix' => ['new@company.example.evil.test'],
            'a lookalike prefix' => ['new@notcompany.example'],
            'the domain in the local part' => ['company.example@example.com'],
        ];
    }

    public function test_the_registration_page_says_who_may_register(): void
    {
        config(['exposehub.allowed_email_domains' => ['company.example', 'private.example']]);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Accounts are limited to @company.example and @private.example addresses.');
    }

    public function test_an_account_cannot_move_to_an_address_outside_the_allowed_domains(): void
    {
        config(['exposehub.allowed_email_domains' => ['company.example']]);
        $user = User::factory()->create(['email' => 'person@company.example']);
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Person')
            ->set('email', 'person@example.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email']);

        $this->assertSame('person@company.example', $user->fresh()->email);
    }

    public function test_an_existing_account_outside_the_domains_can_still_save_its_profile(): void
    {
        config(['exposehub.allowed_email_domains' => ['company.example']]);
        $user = User::factory()->create(['email' => 'early@example.com']);
        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Renamed')
            ->set('email', 'early@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $user->fresh()->name);
    }

    public function test_a_hidden_domain_works_but_is_never_named(): void
    {
        config([
            'exposehub.allowed_email_domains' => ['company.example', 'private.example'],
            'exposehub.hidden_email_domains' => ['Private.example'],
        ]);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Accounts are limited to @company.example addresses.')
            ->assertDontSee('private.example');

        $this->register('someone@example.com')->assertSessionHasErrors([
            'email' => 'Accounts are limited to @company.example addresses.',
        ]);

        $this->register('new@private.example')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new@private.example']);
    }

    public function test_when_every_allowed_domain_is_hidden_none_is_named(): void
    {
        config([
            'exposehub.allowed_email_domains' => ['private.example'],
            'exposehub.hidden_email_domains' => ['private.example'],
        ]);

        $this->get(route('register'))->assertSee('Accounts are limited to certain email domains.')->assertDontSee('private.example');
        $this->register('someone@example.com')->assertSessionHasErrors(['email' => 'Accounts are limited to certain email domains.']);
    }
}
