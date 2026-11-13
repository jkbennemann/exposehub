<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamSettingsLinkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The team's settings (members, invitations, single sign-on) could only
     * be reached through a pencil icon three clicks away. Reported from
     * production on the day single sign-on was released.
     */
    public function test_the_team_switcher_leads_to_the_settings_of_the_current_team(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Blue', 'is_personal' => false]);
        $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        $this->actingAs($owner)->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertSee('data-test="team-switcher-settings"', false)
            ->assertSee('href="'.route('teams.edit', ['team' => $team->slug]).'"', false)
            ->assertSee('Team settings')
            ->assertSee('href="'.route('teams.index').'"', false);

        // The list of teams says in words where each team's settings are.
        $this->get(route('teams.index'))->assertOk()->assertSee('Team settings')->assertSee('href="'.route('teams.edit', $team->slug).'"', false);
        $this->get(route('teams.edit', ['team' => $team->slug]))->assertOk()->assertSee('data-test="team-sso"', false);
    }

    public function test_a_visitor_of_the_demo_is_not_shown_a_way_to_pages_that_are_closed_to_them(): void
    {
        config(['exposehub.demo.enabled' => true]);
        $visitor = Demo::admit();

        $this->actingAs($visitor)->get(route('dashboard', ['current_team' => Demo::team()->slug]))
            ->assertOk()
            ->assertDontSee('data-test="team-switcher-settings"', false)
            ->assertDontSee('data-test="team-switcher-all"', false);
    }
}
