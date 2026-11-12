<?php

namespace Tests\Feature\Domains;

use App\Enums\TeamRole;
use App\Models\CustomDomain;
use App\Models\User;
use App\Support\Dns\Resolver;
use App\Support\Domains\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CustomDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'exposehub.tunnel_domain' => 'tunnels.example',
            'exposehub.agent_server' => 'https://connect.tunnels.example',
            'exposehub.custom_domain_target' => 'connect.tunnels.example',
        ]);
        $this->dns([], []);
    }

    /**
     * Answer DNS lookups from fixed tables instead of the network.
     *
     * @param  array<string, array<int, string>>  $txt
     * @param  array<string, array<int, string>>  $addresses
     * @param  array<int, string>  $apexes
     */
    private function dns(array $txt, array $addresses, array $apexes = []): void
    {
        $this->app->instance(Resolver::class, new class($txt, $addresses, $apexes) implements Resolver
        {
            public function __construct(private array $txt, private array $addresses, private array $apexes) {}

            public function txt(string $name): array
            {
                return $this->txt[$name] ?? [];
            }

            public function addresses(string $name): array
            {
                return $this->addresses[$name] ?? [];
            }

            public function isZoneApex(string $name): bool
            {
                return in_array($name, $this->apexes, true);
            }
        });
    }

    /**
     * Record what the page asks the provisioner to do.
     */
    private function provisioner(bool $fails = false): object
    {
        $spy = new class($fails) implements Provisioner
        {
            /** @var array<int, string> */
            public array $provisioned = [];

            /** @var array<int, string> */
            public array $removed = [];

            public function __construct(private bool $fails) {}

            public function provision(CustomDomain $domain): void
            {
                if ($this->fails) {
                    throw new RuntimeException('cluster unavailable');
                }
                $this->provisioned[] = $domain->hostname;
            }

            public function remove(CustomDomain $domain): void
            {
                $this->removed[] = $domain->hostname;
            }

            public function issuesCertificates(): bool
            {
                return true;
            }
        };
        $this->app->instance(Provisioner::class, $spy);

        return $spy;
    }

    public function test_domains_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('domains.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSee('No domains yet');
    }

    public function test_an_owner_can_add_a_domain_and_sees_the_records_to_create(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test('pages::domains.index')
            ->set('hostname', ' Dev.Example.ORG ')
            ->call('addDomain')
            ->assertHasNoErrors();

        $domain = CustomDomain::sole();
        $this->assertSame('dev.example.org', $domain->hostname);
        $this->assertNull($domain->verified_at);

        $component->assertSee('Waiting for DNS')
            ->assertSee('_exposehub.dev.example.org')
            ->assertSee('exposehub-verify='.$domain->verification_token)
            ->assertSeeInOrder(['CNAME', 'dev.example.org', 'connect.tunnels.example']);
    }

    public function test_the_top_of_a_domain_is_given_address_records_not_a_cname(): void
    {
        $user = User::factory()->create();
        CustomDomain::factory()->forMember($user, $user->currentTeam)->create(['hostname' => 'example.org']);
        $this->dns([], ['connect.tunnels.example' => ['198.51.100.10', '2001:db8::10']], apexes: ['example.org']);
        $this->actingAs($user);

        Livewire::test('pages::domains.index')
            ->assertSeeInOrder(['A', 'example.org', '198.51.100.10', 'AAAA', 'example.org', '2001:db8::10'])
            ->assertDontSee('CNAME</dt>', false)
            ->assertSee('does not allow a CNAME record');
    }

    #[DataProvider('refusedHostnames')]
    public function test_hostnames_that_are_not_a_domain_of_their_own_are_refused(string $hostname): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::domains.index')
            ->set('hostname', $hostname)
            ->call('addDomain')
            ->assertHasErrors(['hostname']);

        $this->assertDatabaseCount('custom_domains', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedHostnames(): array
    {
        return [
            'a url' => ['https://dev.example.org'],
            'a path' => ['dev.example.org/admin'],
            'a single label' => ['localhost'],
            'an ip address' => ['203.0.113.7'],
            'the tunnel domain' => ['tunnels.example'],
            'a name under the tunnel domain' => ['mine.tunnels.example'],
            'a wildcard' => ['*.example.org'],
        ];
    }

    public function test_a_member_cannot_add_or_remove_domains(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $member = User::factory()->create();
        $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
        $member->switchTeam($team);
        $domain = CustomDomain::factory()->forMember($owner, $team)->create();

        $this->actingAs($member);
        Livewire::test('pages::domains.index')
            ->assertSee($domain->hostname)
            ->assertDontSee('Add domain')
            ->set('hostname', 'dev.example.org')
            ->call('addDomain')
            ->assertForbidden();
        Livewire::test('pages::domains.index')->call('removeDomain', $domain->id)->assertForbidden();

        $this->assertDatabaseCount('custom_domains', 1);
    }

    public function test_a_domain_is_connected_once_both_records_are_in_place(): void
    {
        $user = User::factory()->create();
        $domain = CustomDomain::factory()->forMember($user, $user->currentTeam)->create(['hostname' => 'dev.example.org']);
        $provisioner = $this->provisioner();
        $this->actingAs($user);

        // Nothing published yet.
        Livewire::test('pages::domains.index')
            ->call('checkDns', $domain->id)
            ->assertSee('The TXT record _exposehub.dev.example.org was not found')
            ->assertSee('Waiting for DNS');
        $this->assertFalse($domain->fresh()->isVerified());

        // Ownership proven, but visitors are not sent here yet: only the routing record is still asked for.
        $this->dns(
            ['_exposehub.dev.example.org' => ['unrelated', $domain->txtValue()]],
            ['connect.tunnels.example' => ['198.51.100.10']],
        );
        Livewire::test('pages::domains.index')
            ->call('checkDns', $domain->id)
            ->assertSee('Ownership confirmed, not pointing here yet')
            ->assertSee('dev.example.org does not point to this service yet.')
            ->assertSee('CNAME')
            ->assertDontSee('exposehub-verify=');
        $this->assertTrue($domain->fresh()->isVerified());
        $this->assertFalse($domain->fresh()->isConnected());
        $this->assertSame([], $provisioner->provisioned);

        // Both in place: connected, and the certificate is requested.
        $this->dns(
            ['_exposehub.dev.example.org' => [$domain->txtValue()]],
            ['connect.tunnels.example' => ['198.51.100.10'], 'dev.example.org' => ['198.51.100.10']],
        );
        Livewire::test('pages::domains.index')
            ->call('checkDns', $domain->id)
            ->assertSee('Connected')
            ->assertDontSee('does not point to')
            ->assertSee('--hostname dev.example.org')
            ->assertSee('The certificate is issued within about a minute');
        $this->assertTrue($domain->fresh()->isConnected());
        $this->assertSame(['dev.example.org'], $provisioner->provisioned);
    }

    public function test_a_domain_that_stops_pointing_here_is_no_longer_shown_as_connected(): void
    {
        $user = User::factory()->create();
        $domain = CustomDomain::factory()->forMember($user, $user->currentTeam)->connected()->create(['hostname' => 'dev.example.org']);
        $this->dns([], ['connect.tunnels.example' => ['198.51.100.10'], 'dev.example.org' => ['203.0.113.99']]);
        $this->actingAs($user);

        Livewire::test('pages::domains.index')
            ->call('checkDns', $domain->id)
            ->assertSee('Ownership confirmed, not pointing here yet');

        $this->assertFalse($domain->fresh()->isConnected());
    }

    public function test_a_failing_certificate_setup_is_reported_without_losing_the_connection(): void
    {
        $user = User::factory()->create();
        $domain = CustomDomain::factory()->forMember($user, $user->currentTeam)->verified()->create(['hostname' => 'dev.example.org']);
        $this->dns([], ['connect.tunnels.example' => ['198.51.100.10'], 'dev.example.org' => ['198.51.100.10']]);
        $this->provisioner(fails: true);
        $this->actingAs($user);

        Livewire::test('pages::domains.index')
            ->call('checkDns', $domain->id)
            ->assertSee('setting up the certificate failed');

        $this->assertTrue($domain->fresh()->isConnected());
    }

    public function test_another_teams_record_does_not_verify_a_domain(): void
    {
        $user = User::factory()->create();
        $domain = CustomDomain::factory()->forMember($user, $user->currentTeam)->create(['hostname' => 'dev.example.org']);
        $this->dns(['_exposehub.dev.example.org' => ['exposehub-verify=someone-elses-token']], []);
        $this->actingAs($user);

        Livewire::test('pages::domains.index')->call('checkDns', $domain->id);

        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_a_domain_connected_to_one_team_cannot_be_verified_by_another(): void
    {
        $first = User::factory()->create();
        CustomDomain::factory()->forMember($first, $first->currentTeam)->verified()->create(['hostname' => 'dev.example.org']);

        $second = User::factory()->create();
        $claim = CustomDomain::factory()->forMember($second, $second->currentTeam)->create(['hostname' => 'dev.example.org']);
        $this->dns(['_exposehub.dev.example.org' => [$claim->txtValue()]], []);
        $this->actingAs($second);

        Livewire::test('pages::domains.index')
            ->call('checkDns', $claim->id)
            ->assertSee('dev.example.org is already connected to another team.');

        $this->assertNull($claim->fresh()->verified_at);
    }

    public function test_an_owner_can_remove_a_domain(): void
    {
        $user = User::factory()->create();
        $domain = CustomDomain::factory()->forMember($user, $user->currentTeam)->connected()->create();
        $provisioner = $this->provisioner();
        $this->actingAs($user);

        Livewire::test('pages::domains.index')
            ->call('removeDomain', $domain->id)
            ->assertDontSee($domain->hostname);

        $this->assertDatabaseCount('custom_domains', 0);
        $this->assertSame([$domain->hostname], $provisioner->removed);
    }

    public function test_a_domain_of_another_team_cannot_be_touched(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $domain = CustomDomain::factory()->forMember($stranger, $stranger->currentTeam)->create();
        $this->actingAs($user);

        Livewire::test('pages::domains.index')->call('checkDns', $domain->id)->assertNotFound();
        Livewire::test('pages::domains.index')->call('removeDomain', $domain->id)->assertNotFound();

        $this->assertDatabaseCount('custom_domains', 1);
    }
}
