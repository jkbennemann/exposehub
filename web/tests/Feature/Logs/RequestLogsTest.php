<?php

namespace Tests\Feature\Logs;

use App\Enums\TeamRole;
use App\Models\AgentToken;
use App\Models\LogGrant;
use App\Models\RequestLog;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class RequestLogsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'node-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.node_secret' => self::SECRET]);
    }

    /**
     * A team with an owner and a member, both on it as their current team.
     *
     * @return array{Team, User, User}
     */
    private function team(): array
    {
        $ada = User::factory()->create(['name' => 'Ada']);
        $team = Team::factory()->create(['name' => 'Platform']);
        $team->memberships()->create(['user_id' => $ada->id, 'role' => TeamRole::Owner]);
        $max = User::factory()->create(['name' => 'Max']);
        $team->memberships()->create(['user_id' => $max->id, 'role' => TeamRole::Member]);
        $ada->switchTeam($team);
        $max->switchTeam($team);

        return [$team, $ada, $max];
    }

    private function ingest(array $events, bool $gzip = true): TestResponse
    {
        $body = json_encode(['events' => $events]);

        return $this->call('POST', route('internal.logs'), [], [], [], array_filter([
            'HTTP_AUTHORIZATION' => 'Bearer '.self::SECRET,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_ENCODING' => $gzip ? 'gzip' : null,
        ]), $gzip ? gzencode($body) : $body);
    }

    private function event(AgentToken $token, array $overrides = []): array
    {
        return array_merge([
            'token_id' => (string) $token->id,
            'host' => 'shop.tunnels.example',
            'method' => 'POST',
            'path' => '/webhooks/payment',
            'status' => 202,
            'duration_ms' => 34.2,
            'request_bytes' => 144,
            'response_bytes' => 18,
            'remote' => '203.0.113.0',
            'started' => now()->subSeconds(5)->toRfc3339String(true),
        ], $overrides);
    }

    // ------------------------------------------------------------ ingest

    public function test_the_overview_leads_to_the_request_logs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSee(route('logs.index', ['current_team' => $user->currentTeam->slug]), false)
            ->assertSee('Open your request logs');
    }

    public function test_the_tunnel_server_can_send_logs_and_they_belong_to_the_tokens_owner(): void
    {
        [$team, $ada] = $this->team();
        $token = AgentToken::factory()->forMember($ada, $team)->create();

        $this->ingest([$this->event($token), $this->event($token, ['path' => '/', 'method' => 'get', 'status' => 500])])
            ->assertStatus(202)->assertJson(['accepted' => 2]);

        $this->assertSame(2, RequestLog::query()->where(['team_id' => $team->id, 'user_id' => $ada->id, 'agent_token_id' => $token->id])->count());
        $this->assertDatabaseHas('request_logs', ['method' => 'GET', 'status' => 500]);
    }

    public function test_ingest_needs_the_node_secret(): void
    {
        $this->postJson(route('internal.logs'), ['events' => []])->assertUnauthorized();
    }

    public function test_events_for_unknown_or_deleted_tokens_are_dropped(): void
    {
        [$team, $ada] = $this->team();
        $token = AgentToken::factory()->forMember($ada, $team)->create();

        $this->ingest([
            $this->event($token),
            array_merge($this->event($token), ['token_id' => '999999']),
            array_merge($this->event($token), ['token_id' => 'static:abc']),
        ], gzip: false)->assertJson(['accepted' => 1]);
    }

    public function test_details_are_stored_only_when_sent(): void
    {
        [$team, $ada] = $this->team();
        $token = AgentToken::factory()->forMember($ada, $team)->create();
        $details = ['request_header' => ['Content-Type' => ['application/json']], 'request_body' => base64_encode('{"a":1}'), 'response_header' => [], 'response_body' => '', 'request_truncated' => false, 'response_truncated' => false];

        $this->ingest([$this->event($token), $this->event($token, ['details' => $details])]);

        $this->assertSame(1, RequestLog::query()->whereNull('details')->count());
        $this->assertSame($details, RequestLog::query()->whereNotNull('details')->sole()->details);
    }

    // ------------------------------------------------------------ who sees what

    public function test_a_person_sees_only_their_own_logs(): void
    {
        [$team, $ada, $max] = $this->team();
        RequestLog::factory()->forMember($ada, $team)->create(['path' => '/ada-only']);
        RequestLog::factory()->forMember($max, $team)->create(['path' => '/max-only']);

        $this->actingAs($ada);
        Livewire::test('pages::logs.index')->assertSee('/ada-only')->assertDontSee('/max-only');

        // Not even with the filter set to everything, and not as the team's owner.
        Livewire::test('pages::logs.index')->set('owner', 'all')->assertDontSee('/max-only');
        Livewire::test('pages::logs.index')->set('owner', (string) $max->id)->assertDontSee('/max-only');
    }

    public function test_sharing_lets_a_colleague_see_the_logs_and_mails_them(): void
    {
        Notification::fake();
        [$team, $ada, $max] = $this->team();
        RequestLog::factory()->forMember($ada, $team)->create(['path' => '/ada-only']);

        $this->actingAs($ada);
        Livewire::test('pages::logs.index')->set('shareWith', (string) $max->id)->call('share')->assertHasNoErrors();
        Notification::assertSentTo($max, AccountNotice::class, fn (AccountNotice $n) => $n->kind === 'logs-shared');

        $this->actingAs($max);
        Livewire::test('pages::logs.index')
            ->assertDontSee('/ada-only') // the default is "my tunnels"
            ->assertSee('Shared with you by Ada.')
            ->set('owner', (string) $ada->id)
            ->assertSee('/ada-only');
    }

    public function test_stopping_to_share_hides_the_logs_again(): void
    {
        [$team, $ada, $max] = $this->team();
        RequestLog::factory()->forMember($ada, $team)->create(['path' => '/ada-only']);
        $grant = LogGrant::query()->create(['team_id' => $team->id, 'owner_id' => $ada->id, 'viewer_id' => $max->id]);

        $this->actingAs($ada);
        Livewire::test('pages::logs.index')->call('stopSharing', $grant->id);

        $this->actingAs($max);
        Livewire::test('pages::logs.index')->set('owner', 'all')->assertDontSee('/ada-only');
    }

    public function test_one_cannot_share_someone_elses_logs_or_with_a_stranger(): void
    {
        [$team, $ada, $max] = $this->team();
        $stranger = User::factory()->create();
        $grant = LogGrant::query()->create(['team_id' => $team->id, 'owner_id' => $ada->id, 'viewer_id' => $max->id]);

        $this->actingAs($max);
        Livewire::test('pages::logs.index')->call('stopSharing', $grant->id)->assertNotFound();
        $this->assertModelExists($grant);

        $this->actingAs($ada);
        Livewire::test('pages::logs.index')->set('shareWith', (string) $stranger->id)->call('share')->assertForbidden();
        $this->assertDatabaseMissing('log_grants', ['viewer_id' => $stranger->id]);
    }

    public function test_leaving_the_team_deletes_ones_logs_and_ends_sharing(): void
    {
        [$team, $ada, $max] = $this->team();
        RequestLog::factory()->forMember($max, $team)->create();
        LogGrant::query()->create(['team_id' => $team->id, 'owner_id' => $max->id, 'viewer_id' => $ada->id]);

        $this->actingAs($ada);
        Livewire::test('pages::teams.remove-member-modal', ['team' => $team, 'memberId' => $max->id])->call('removeMember');

        $this->assertSame(0, RequestLog::query()->where('user_id', $max->id)->count());
        $this->assertDatabaseCount('log_grants', 0);
    }

    public function test_deleting_the_team_deletes_its_logs(): void
    {
        [$team, $ada] = $this->team();
        RequestLog::factory()->forMember($ada, $team)->count(3)->create();

        $this->actingAs($ada);
        Livewire::test('pages::teams.delete-team-modal', ['team' => $team])->set('deleteName', 'Platform')->call('deleteTeam');

        $this->assertDatabaseCount('request_logs', 0);
    }

    // ------------------------------------------------------------ the page

    public function test_filters_and_numbers_follow_what_is_selected(): void
    {
        [$team, $ada] = $this->team();
        foreach ([[200, 10], [200, 20], [404, 30], [500, 300]] as [$status, $ms]) {
            RequestLog::factory()->forMember($ada, $team)->create(['status' => $status, 'duration_ms' => $ms, 'path' => "/p{$status}?x=1"]);
        }
        RequestLog::factory()->forMember($ada, $team)->create(['path' => '/old', 'started_at' => now()->subDays(2)]);
        $this->actingAs($ada);

        $page = Livewire::test('pages::logs.index');
        $this->assertSame(['count' => 4, 'errors' => 2, 'p50' => 30.0, 'p95' => 300.0], $page->get('stats'));
        $page->assertDontSee('/old');
        $this->assertSame('/p200', $page->get('busiest')[0]['path'], 'busiest paths are counted without their query strings');

        $page->call('toggleStatus', '2');
        $this->assertSame(2, $page->get('stats')['count']);

        $page->call('setRange', '7d')->call('toggleStatus', '2');
        $this->assertSame(5, $page->get('stats')['count']);

        $page->set('q', '500');
        $this->assertSame(1, $page->get('stats')['count']);
    }

    public function test_a_request_opens_to_its_details_or_explains_how_to_keep_them(): void
    {
        [$team, $ada] = $this->team();
        $plain = RequestLog::factory()->forMember($ada, $team)->create();
        $detailed = RequestLog::factory()->forMember($ada, $team)->create(['details' => [
            'request_header' => ['Content-Type' => ['application/json'], 'Authorization' => ['[redacted]']],
            'request_body' => base64_encode('{"amount":12990}'),
            'response_header' => [], 'response_body' => '', 'request_truncated' => false, 'response_truncated' => false,
        ]]);
        $this->actingAs($ada);

        Livewire::test('pages::logs.index')->call('toggle', $plain->id)->assertSee('--log-bodies');
        Livewire::test('pages::logs.index')->call('toggle', $detailed->id)
            ->assertSee('[redacted]')
            ->assertSee('"amount": 12990');
    }

    public function test_an_empty_log_says_how_to_fill_it(): void
    {
        [$team, $ada] = $this->team();
        $this->actingAs($ada);

        $this->get(route('logs.index', ['current_team' => $team->slug]))->assertOk()->assertSee('No requests logged yet');
    }

    // ------------------------------------------------------------ retention

    public function test_logs_older_than_the_retention_period_are_pruned(): void
    {
        [$team, $ada] = $this->team();
        config(['exposehub.log_retention_days' => 7]);
        $kept = RequestLog::factory()->forMember($ada, $team)->create(['started_at' => now()->subDays(6)]);
        RequestLog::factory()->forMember($ada, $team)->create(['started_at' => now()->subDays(8)]);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertSame([$kept->id], RequestLog::query()->pluck('id')->all());
    }

    public function test_pruning_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('logs:prune')->assertSuccessful();
    }
}
