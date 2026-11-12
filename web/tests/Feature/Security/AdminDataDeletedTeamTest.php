<?php

namespace Tests\Feature\Security;

use App\Enums\TeamRole;
use App\Models\Network;
use App\Models\SavedAddress;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Deleting a team releases the names it saved, so other teams can save them.
 */
class AdminDataDeletedTeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exposehub.tunnel_domain' => 'tunnels.example',
            'exposehub.agent_server' => 'https://connect.tunnels.example',
        ]);
        Notification::fake();
    }

    public function test_deleting_a_team_releases_its_saved_addresses_and_networks(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Throwaway']);
        $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
        $owner->switchTeam($team);

        $address = $team->savedAddresses()->create(['hostname' => 'brand.tunnels.example', 'access' => 'networks']);
        $network = $team->accessNetworks()->create(['name' => 'Office', 'entries' => [['cidr' => '203.0.113.0/24', 'note' => null]]]);
        $address->networks()->attach($network);

        Livewire::actingAs($owner)->test('pages::teams.delete-team-modal', ['team' => $team])
            ->set('deleteName', 'Throwaway')->call('deleteTeam')->assertHasNoErrors();

        $this->assertSoftDeleted($team);
        $this->assertSame(0, SavedAddress::where('hostname', 'brand.tunnels.example')->count());
        $this->assertSame(0, Network::where('team_id', $team->id)->count());
        $this->assertSame(0, DB::table('access_networks')->count());

        $other = User::factory()->create();
        Livewire::actingAs($other)->test('pages::access.index')
            ->set('name', 'brand')->call('saveAddress')->assertHasNoErrors();

        $this->assertDatabaseHas('saved_addresses', ['hostname' => 'brand.tunnels.example', 'team_id' => $other->currentTeam->id]);
    }

    public function test_the_migration_releases_addresses_teams_deleted_before_the_fix_left_behind(): void
    {
        $deleted = Team::factory()->create();
        $deleted->savedAddresses()->create(['hostname' => 'gone.tunnels.example']);
        $deleted->accessNetworks()->create(['name' => 'Office', 'entries' => [['cidr' => '203.0.113.0/24', 'note' => null]]]);
        $deleted->delete();

        $kept = Team::factory()->create();
        $kept->savedAddresses()->create(['hostname' => 'kept.tunnels.example']);
        $kept->accessNetworks()->create(['name' => 'Office', 'entries' => [['cidr' => '203.0.113.0/24', 'note' => null]]]);

        (require database_path('migrations/2026_11_13_000003_release_saved_addresses_of_deleted_teams.php'))->up();

        $this->assertSame(['kept.tunnels.example'], SavedAddress::pluck('hostname')->all());
        $this->assertSame([$kept->id], Network::pluck('team_id')->all());
    }
}
