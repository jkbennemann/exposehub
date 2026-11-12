<?php

namespace Tests\Feature\Internal;

use App\Enums\TeamRole;
use App\Models\AgentToken;
use App\Models\CustomDomain;
use App\Models\Team;
use App\Models\User;
use App\Support\ServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionFunction;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class SnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.node_secret' => self::SECRET]);
    }

    /**
     * $count networks of 100 entries with long notes, as saveNetwork stores them.
     */
    private function seedNetworks(Team $team, int $count): void
    {
        $entries = json_encode(array_map(fn (int $i) => [
            'cidr' => sprintf('2001:db8:%x::/48', $i),
            'note' => str_repeat('n', 120),
        ], range(1, 100)));
        foreach (array_chunk(range(1, $count), 100) as $chunk) {
            DB::table('networks')->insert(array_map(fn (int $i) => [
                'team_id' => $team->id, 'name' => "net-{$i}", 'entries' => $entries, 'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }
    }

    /**
     * Sends the body the way PHP does in production, with output_buffering =
     * 4096, and throws the output away.
     */
    private function send(StreamedResponse $response): void
    {
        ob_start(fn () => '', 4096);
        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }
    }

    public function test_the_snapshot_is_closed_without_the_node_secret(): void
    {
        $this->getJson(route('internal.snapshot'))->assertUnauthorized();
        $this->withToken('wrong')->getJson(route('internal.snapshot'))->assertUnauthorized();
    }

    public function test_the_snapshot_is_closed_while_no_secret_is_configured(): void
    {
        config(['exposehub.node_secret' => null]);

        $this->withToken('')->getJson(route('internal.snapshot'))->assertUnauthorized();
        $this->getJson(route('internal.snapshot'))->assertUnauthorized();
    }

    public function test_the_snapshot_lists_usable_tokens_by_hash(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $token = AgentToken::factory()->forMember($user, $team)->create();

        $this->withToken(self::SECRET)
            ->getJson(route('internal.snapshot'))
            ->assertOk()
            ->assertExactJson(['reserved' => [], 'teams' => [], 'domains' => [], 'disconnects' => [], 'tokens' => [[
                'id' => $token->id,
                'hash' => $token->hash,
                'team_id' => $team->id,
                'team' => $team->slug,
                'user_id' => $user->id,
            ]]]);
    }

    public function test_the_snapshot_leaves_out_tokens_that_may_no_longer_connect(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $kept = AgentToken::factory()->forMember($owner, $team)->create();

        // Revoked.
        AgentToken::factory()->forMember($owner, $team)->revoked()->create();

        // Its creator has left the team.
        $former = User::factory()->create();
        $membership = $team->memberships()->create(['user_id' => $former->id, 'role' => TeamRole::Member]);
        AgentToken::factory()->forMember($former, $team)->create();
        $membership->delete();

        // Its team has been deleted.
        $gone = User::factory()->create();
        AgentToken::factory()->forMember($gone, $gone->currentTeam)->create();
        $gone->currentTeam->delete();

        $this->withToken(self::SECRET)
            ->getJson(route('internal.snapshot'))
            ->assertOk()
            ->assertJsonCount(1, 'tokens')
            ->assertJsonPath('tokens.0.id', $kept->id);
    }

    public function test_the_snapshot_lists_only_verified_domains(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        CustomDomain::factory()->forMember($user, $team)->verified()->create(['hostname' => 'app.example.org']);
        CustomDomain::factory()->forMember($user, $team)->create(['hostname' => 'pending.example.org']);

        $this->withToken(self::SECRET)
            ->getJson(route('internal.snapshot'))
            ->assertOk()
            ->assertExactJson(['tokens' => [], 'reserved' => [], 'teams' => [], 'disconnects' => [], 'domains' => [['hostname' => 'app.example.org', 'team_id' => $team->id]]]);
    }

    public function test_an_unchanged_snapshot_answers_not_modified(): void
    {
        $user = User::factory()->create();
        AgentToken::factory()->forMember($user, $user->currentTeam)->create();

        $etag = $this->withToken(self::SECRET)
            ->getJson(route('internal.snapshot'))
            ->assertOk()
            ->headers->get('ETag');

        $this->assertNotEmpty($etag);

        $this->withToken(self::SECRET)
            ->withHeader('If-None-Match', $etag)
            ->getJson(route('internal.snapshot'))
            ->assertStatus(304);

        AgentToken::factory()->forMember($user, $user->currentTeam)->create();

        $this->withToken(self::SECRET)
            ->withHeader('If-None-Match', $etag)
            ->getJson(route('internal.snapshot'))
            ->assertOk()
            ->assertJsonCount(2, 'tokens');
    }

    /**
     * Many networks with long notes once made the snapshot run out of memory,
     * and every poll after that failed, which froze revocations on the
     * tunnel server for all teams.
     */
    public function test_the_snapshot_is_built_in_bounded_memory_however_many_networks_teams_add(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        AgentToken::factory()->forMember($user, $team)->create();

        $this->seedNetworks($team, 1000);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $response = $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk();
        $used = memory_get_peak_usage() - $before;

        // Holding it all took over 20 MB here; streamed it takes about 4.
        $this->assertLessThan(12 << 20, $used, sprintf('building the snapshot took %.1f MB', $used / (1 << 20)));

        $response->assertJsonCount(1, 'tokens')->assertJsonCount(1000, 'teams.0.networks')->assertJsonCount(100, 'teams.0.networks.999.cidrs');
        $this->assertSame('2001:db8:1::/48', $response->json('teams.0.networks.0.cidrs.0'));
        $this->assertStringNotContainsString(str_repeat('n', 120), $response->streamedContent());
    }

    public function test_a_snapshot_that_cannot_be_built_fails_whole_and_is_not_recorded_as_fetched(): void
    {
        $user = User::factory()->create();
        AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        DB::table('networks')->insert([
            'team_id' => $user->currentTeam->id, 'name' => 'broken', 'entries' => 'not json', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->withToken(self::SECRET)->getJson(route('internal.snapshot'));

        $response->assertServerError();
        $this->assertNull($response->json('tokens'));
        $this->assertNull(ServiceStatus::lastSnapshot());

        DB::table('networks')->delete();
        $response = $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk();
        // Built, but not sent yet.
        $this->assertNull(ServiceStatus::lastSnapshot());
        $response->streamedContent();
        $this->assertNotNull(ServiceStatus::lastSnapshot());
    }

    /**
     * Copied out in one piece, a large body landed whole in PHP's output
     * buffer: as much memory again as the snapshot is big.
     */
    public function test_sending_the_snapshot_takes_little_memory_with_output_buffering_on(): void
    {
        $user = User::factory()->create();
        $this->seedNetworks($user->currentTeam, 3000);

        $response = $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk();
        $this->assertGreaterThan(4 << 20, (int) $response->headers->get('Content-Length'));
        $base = $response->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $base);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $this->send($base);
        $used = memory_get_peak_usage() - $before;

        $this->assertLessThan(1 << 20, $used, sprintf('sending the snapshot took %.1f MB', $used / (1 << 20)));
        $this->assertNotNull(ServiceStatus::lastSnapshot());
    }

    public function test_a_snapshot_whose_sending_fails_partway_is_not_recorded_as_fetched(): void
    {
        $user = User::factory()->create();
        AgentToken::factory()->forMember($user, $user->currentTeam)->create();
        $this->seedNetworks($user->currentTeam, 50);

        $base = $this->withToken(self::SECRET)->getJson(route('internal.snapshot'))->assertOk()->baseResponse;
        $this->assertInstanceOf(StreamedResponse::class, $base);

        // The built body loses its end before it is sent.
        $callback = $base->getCallback();
        $this->assertNotNull($callback);
        $body = (new ReflectionFunction($callback(...)))->getClosureUsedVariables()['body'];
        ftruncate($body, 1000);

        try {
            $this->send($base);
            $this->fail('A cut-off snapshot was sent as complete.');
        } catch (RuntimeException) {
            //
        }

        $this->assertNull(ServiceStatus::lastSnapshot());
    }
}
