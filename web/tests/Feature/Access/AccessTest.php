<?php

namespace Tests\Feature\Access;

use App\Enums\TeamRole;
use App\Models\CustomDomain;
use App\Models\Network;
use App\Models\SavedAddress;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exposehub.tunnel_domain' => 'tunnels.example',
            'exposehub.agent_server' => 'https://connect.tunnels.example',
            'exposehub.node_secret' => self::SECRET,
        ]);
    }

    /**
     * @return array{Team, User, User}
     */
    private function teamWithMember(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Shop crew']);
        $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
        $member = User::factory()->create();
        $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
        $owner->switchTeam($team);
        $member->switchTeam($team);

        return [$team, $owner, $member];
    }

    private function network(Team $team, string $name = 'Office VPN', array $cidrs = ['203.0.113.10/32']): Network
    {
        return $team->accessNetworks()->create(['name' => $name, 'entries' => array_map(fn ($c) => ['cidr' => $c, 'note' => null], $cidrs)]);
    }

    public function test_the_page_shows_the_rules_to_every_member(): void
    {
        [$team, , $member] = $this->teamWithMember();

        $this->actingAs($member)->get(route('access.index', ['current_team' => $team->slug]))
            ->assertOk()->assertSee('Team default')->assertSee('Saved addresses')->assertSee('.tunnels.example');
    }

    public function test_a_member_saves_an_address_that_is_then_the_teams_alone(): void
    {
        [$team, , $member] = $this->teamWithMember();

        Livewire::actingAs($member)->test('pages::access.index')->set('name', 'Shop')->call('saveAddress')->assertHasNoErrors();
        $this->assertDatabaseHas('saved_addresses', ['team_id' => $team->id, 'hostname' => 'shop.tunnels.example', 'user_id' => $member->id]);

        $other = User::factory()->create();
        Livewire::actingAs($other)->test('pages::access.index')->set('name', 'shop')->call('saveAddress')->assertHasErrors('name');
        Livewire::actingAs($member)->test('pages::access.index')->set('name', 'connect')->call('saveAddress')->assertHasErrors('name');
        Livewire::actingAs($member)->test('pages::access.index')->set('name', '-bad')->call('saveAddress')->assertHasErrors('name');
    }

    public function test_a_team_saves_at_most_so_many_addresses(): void
    {
        [$team, $owner] = $this->teamWithMember();
        foreach (range(1, SavedAddress::LIMIT) as $i) {
            $team->savedAddresses()->create(['hostname' => "a{$i}.tunnels.example"]);
        }

        Livewire::actingAs($owner)->test('pages::access.index')->set('name', 'onemore')->call('saveAddress')->assertHasErrors('name');
    }

    public function test_owners_keep_networks_normalised_and_members_cannot_change_them(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();

        Livewire::actingAs($owner)->test('pages::access.index')
            ->call('editNetwork')
            ->set('networkName', 'Office VPN')
            ->set('networkEntries', "203.0.113.10   # Münster exit\n198.51.100.9/24\n2001:db8:42:1::5/48\n203.0.113.10")
            ->call('saveNetwork')->assertHasNoErrors();

        $network = $team->accessNetworks()->sole();
        $this->assertSame(['203.0.113.10/32', '198.51.100.0/24', '2001:db8:42::/48'], $network->cidrs());
        $this->assertSame('Münster exit', $network->entries[0]['note']);

        Livewire::actingAs($owner)->test('pages::access.index')->call('editNetwork')
            ->set('networkName', 'Bad')->set('networkEntries', 'vpn.example.com')->call('saveNetwork')->assertHasErrors('networkEntries');
        Livewire::actingAs($owner)->test('pages::access.index')->call('editNetwork')
            ->set('networkName', 'All')->set('networkEntries', '0.0.0.0/0')->call('saveNetwork')->assertHasErrors('networkEntries');

        Livewire::actingAs($member)->test('pages::access.index')->call('editNetwork')->assertForbidden();
    }

    public function test_a_member_cannot_open_an_address_the_team_keeps_closed(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        $vpn = $this->network($team);
        $team->update(['access' => 'networks']);
        $team->networks()->sync([$vpn->id]);
        $address = $team->savedAddresses()->create(['hostname' => 'hooks.tunnels.example', 'user_id' => $member->id]);

        Livewire::actingAs($member)->test('pages::access.index')
            ->call('editRule', 'address', $address->id)->set('ruleAccess', 'anyone')->call('saveRule')->assertHasErrors('ruleAccess');
        $this->assertSame('default', $address->refresh()->access);

        // Restricting further is fine for the member who saved it ...
        Livewire::actingAs($member)->test('pages::access.index')
            ->call('editRule', 'address', $address->id)->set('ruleAccess', 'networks')->set('ruleNetworks', [$vpn->id])->call('saveRule')->assertHasNoErrors();
        // ... and an owner may open it, for webhooks from outside.
        Livewire::actingAs($owner)->test('pages::access.index')
            ->call('editRule', 'address', $address->id)->set('ruleAccess', 'anyone')->call('saveRule')->assertHasNoErrors();
        $this->assertSame('anyone', $address->refresh()->access);

        // Members do not set the team default.
        Livewire::actingAs($member)->test('pages::access.index')->call('editRule', 'team')->assertForbidden();
    }

    public function test_a_rule_limited_to_networks_needs_one(): void
    {
        [$team, $owner] = $this->teamWithMember();

        Livewire::actingAs($owner)->test('pages::access.index')
            ->call('editRule', 'team')->set('ruleAccess', 'networks')->set('ruleNetworks', [])->call('saveRule')->assertHasErrors('ruleNetworks');
    }

    public function test_the_tunnel_server_gets_saved_names_networks_and_rules(): void
    {
        [$team] = $this->teamWithMember();
        $vpn = $this->network($team, 'Office VPN', ['203.0.113.10/32', '198.51.100.0/24']);
        $team->update(['access' => 'networks']);
        $team->networks()->sync([$vpn->id]);
        $team->savedAddresses()->create(['hostname' => 'shop.tunnels.example']);
        $team->savedAddresses()->create(['hostname' => 'hooks.tunnels.example', 'access' => 'anyone']);
        $domain = CustomDomain::factory()->for($team)->create(['hostname' => 'dev.example.com', 'verified_at' => now(), 'access' => 'networks']);
        $domain->networks()->sync([$vpn->id]);

        $snapshot = $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk()->json();

        $this->assertEqualsCanonicalizing([
            ['hostname' => 'shop.tunnels.example', 'team_id' => $team->id],
            ['hostname' => 'hooks.tunnels.example', 'team_id' => $team->id],
        ], $snapshot['reserved']);
        $this->assertSame([[
            'team_id' => $team->id,
            'default' => ['networks' => ['Office VPN']],
            'networks' => [['name' => 'Office VPN', 'cidrs' => ['203.0.113.10/32', '198.51.100.0/24']]],
            'addresses' => [
                ['hostname' => 'hooks.tunnels.example', 'anyone' => true],
                ['hostname' => 'dev.example.com', 'access' => ['networks' => ['Office VPN']]],
            ],
        ]], $snapshot['teams']);
    }
}
