<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_address_gets_our_not_found_page(): void
    {
        $this->get('/no/such/page')
            ->assertNotFound()
            ->assertSee('This page does not exist')
            ->assertSee('/no/such/page')
            ->assertSee('Error 404')
            ->assertSee('Go to the start page');
    }

    public function test_another_teams_page_gets_our_forbidden_page(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->get(route('tokens.index', ['current_team' => $other->currentTeam->slug]))
            ->assertForbidden()
            ->assertSee('You do not have access to this page');
    }

    #[DataProvider('statuses')]
    public function test_every_error_status_has_a_page_in_our_design(int $status, string $title): void
    {
        Route::get('/_test/abort/{status}', fn (int $status) => abort($status));

        $this->get("/_test/abort/{$status}")
            ->assertStatus($status)
            ->assertSee($title)
            ->assertSee("Error {$status}")
            ->assertSee(config('app.name'));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function statuses(): array
    {
        return [
            '401' => [401, 'Sign in to continue'],
            '403' => [403, 'You do not have access to this page'],
            '404' => [404, 'This page does not exist'],
            '419' => [419, 'This page has expired'],
            '429' => [429, 'Too many requests'],
            '500' => [500, 'Something went wrong on this server'],
            '503' => [503, 'Down for maintenance'],
        ];
    }
}
