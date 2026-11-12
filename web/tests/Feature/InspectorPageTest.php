<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_inspector_page_explains_how_to_open_and_use_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('inspector', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSeeInOrder(['Start a tunnel', 'exposehub http 8000', 'Open it in your browser', 'http://127.0.0.1:4040'], false)
            ->assertSee('Debug webhooks')
            ->assertSee('--inspect off')
            ->assertSee('What it shows stays on your machine.');
    }

    public function test_the_navigation_and_the_overview_lead_to_it(): void
    {
        $user = User::factory()->create();
        $inspector = route('inspector', ['current_team' => $user->currentTeam->slug]);

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSee($inspector, false)
            ->assertSee('How the inspector works');
    }

    public function test_guests_cannot_see_it(): void
    {
        $user = User::factory()->create();

        $this->get(route('inspector', ['current_team' => $user->currentTeam->slug]))->assertRedirect(route('login'));
    }
}
