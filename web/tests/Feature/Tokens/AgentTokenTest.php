<?php

namespace Tests\Feature\Tokens;

use App\Enums\TeamRole;
use App\Models\AgentToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentTokenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A team with an owner and one plain member.
     *
     * @return array{Team, User, User}
     */
    private function teamWithMember(): array
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $member = User::factory()->create();
        $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
        $member->switchTeam($team);

        return [$team, $owner, $member];
    }

    public function test_tokens_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('tokens.index', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSee('No tokens yet');
    }

    public function test_tokens_page_of_another_team_is_forbidden(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->get(route('tokens.index', ['current_team' => $other->currentTeam->slug]))
            ->assertForbidden();
    }

    public function test_a_token_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test('pages::tokens.index')
            ->set('name', 'Work laptop')
            ->call('createToken')
            ->assertHasNoErrors();

        $plain = $component->get('plainToken');
        $token = AgentToken::sole();

        $this->assertStringStartsWith(AgentToken::PREFIX, $plain);
        $this->assertSame(43, strlen($plain));
        $this->assertSame(hash('sha256', $plain), $token->hash);
        $this->assertSame(substr($plain, 0, 9), $token->prefix);
        $this->assertSame($user->currentTeam->id, $token->team_id);
        $this->assertSame($user->id, $token->user_id);
        $this->assertStringNotContainsString($plain, json_encode($token->getAttributes()));

        $component->assertSee($plain)
            ->call('dismissToken')
            ->assertDontSee($plain)
            ->assertSee('Work laptop');
    }

    public function test_a_token_needs_a_name(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::tokens.index')
            ->call('createToken')
            ->assertHasErrors(['name' => 'required']);

        $this->assertDatabaseCount('agent_tokens', 0);
    }

    public function test_members_see_only_their_own_tokens_and_managers_see_all(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        AgentToken::factory()->forMember($owner, $team)->create(['name' => 'Owner desktop']);
        AgentToken::factory()->forMember($member, $team)->create(['name' => 'Member laptop']);

        $this->actingAs($member);
        Livewire::test('pages::tokens.index')
            ->assertSee('Member laptop')
            ->assertDontSee('Owner desktop');

        $this->actingAs($owner);
        Livewire::test('pages::tokens.index')
            ->assertSee('Member laptop')
            ->assertSee('Owner desktop');
    }

    public function test_a_member_can_revoke_their_own_token(): void
    {
        [$team, , $member] = $this->teamWithMember();
        $token = AgentToken::factory()->forMember($member, $team)->create();

        $this->actingAs($member);
        Livewire::test('pages::tokens.index')
            ->call('revokeToken', $token->id)
            ->assertDontSee($token->name);

        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_a_member_cannot_revoke_someone_elses_token(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        $token = AgentToken::factory()->forMember($owner, $team)->create();

        $this->actingAs($member);
        Livewire::test('pages::tokens.index')
            ->call('revokeToken', $token->id)
            ->assertForbidden();

        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_an_owner_can_revoke_a_members_token(): void
    {
        [$team, $owner, $member] = $this->teamWithMember();
        $token = AgentToken::factory()->forMember($member, $team)->create();

        $this->actingAs($owner);
        Livewire::test('pages::tokens.index')->call('revokeToken', $token->id);

        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_a_token_of_another_team_cannot_be_revoked(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $token = AgentToken::factory()->forMember($stranger, $stranger->currentTeam)->create();

        $this->actingAs($user);
        Livewire::test('pages::tokens.index')
            ->call('revokeToken', $token->id)
            ->assertNotFound();

        $this->assertNull($token->fresh()->revoked_at);
    }
}
