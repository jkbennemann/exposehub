<?php

namespace Tests\Feature\Security;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Network names identify a network on the tunnel server ignoring case, and
 * every team's networks travel there in one shared snapshot.
 */
class AccessNetworkLimitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Team, User}
     */
    private function teamWithOwner(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        return [$team, $owner];
    }

    private function addNetwork(User $owner, string $name, ?int $id = null): Testable
    {
        return Livewire::actingAs($owner)->test('pages::access.index')
            ->call('editNetwork', $id)
            ->set('networkName', $name)
            ->set('networkEntries', '203.0.113.10')
            ->call('saveNetwork');
    }

    public function test_network_names_that_differ_only_in_case_or_spaces_are_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $this->addNetwork($owner, 'Office VPN')->assertHasNoErrors();

        $this->addNetwork($owner, 'office vpn')->assertHasErrors('networkName');
        $this->addNetwork($owner, "  OFFICE VPN\u{00A0}")->assertHasErrors('networkName');

        // Renaming a network to its own name in other letters is fine.
        $network = $team->accessNetworks()->firstOrFail();
        $this->addNetwork($owner, 'OFFICE VPN', $network->id)->assertHasNoErrors();
        $this->assertSame(['OFFICE VPN'], $team->accessNetworks()->pluck('name')->all());
    }

    public function test_the_database_refuses_names_that_differ_only_in_case(): void
    {
        [$team] = $this->teamWithOwner();
        $team->accessNetworks()->create(['name' => 'VPN', 'entries' => []]);

        $this->expectException(QueryException::class);
        $team->accessNetworks()->create(['name' => 'vpn', 'entries' => []]);
    }

    public function test_the_migration_renames_existing_duplicates_and_keeps_them(): void
    {
        [$team] = $this->teamWithOwner();
        $other = Team::factory()->create();
        DB::statement('drop index networks_team_id_lower_name_unique');
        foreach ([[$team, 'VPN'], [$team, 'vpn '], [$team, 'Vpn'], [$other, 'vpn']] as [$owner, $name]) {
            $owner->accessNetworks()->create(['name' => $name, 'entries' => []]);
        }

        (require database_path('migrations/2026_11_13_000004_make_network_names_unique_ignoring_case.php'))->up();

        $this->assertSame(['VPN', 'vpn (2)', 'Vpn (3)'], $team->accessNetworks()->orderBy('id')->pluck('name')->all());
        $this->assertSame(['vpn'], $other->accessNetworks()->pluck('name')->all());
    }

    public function test_a_team_has_a_limited_number_of_networks(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        for ($i = 1; $i <= 25; $i++) {
            $team->accessNetworks()->create(['name' => "Network {$i}", 'entries' => [['cidr' => '203.0.113.10/32', 'note' => null]]]);
        }

        $this->addNetwork($owner, 'One more')->assertHasErrors('networkName');
        $this->assertSame(25, $team->accessNetworks()->count());

        // Editing one of them still works.
        $this->addNetwork($owner, 'Network 1 renamed', $team->accessNetworks()->firstOrFail()->id)->assertHasNoErrors();
    }
}
