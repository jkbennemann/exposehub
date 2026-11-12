<?php

namespace Database\Factories;

use App\Models\AgentToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentToken>
 */
class AgentTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = AgentToken::PREFIX.Str::random(40);

        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'prefix' => substr($plain, 0, 9),
            'hash' => AgentToken::hashOf($plain),
        ];
    }

    /**
     * A token created by a member of the team.
     */
    public function forMember(User $user, Team $team): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'team_id' => $team->id]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
