<?php

namespace Database\Factories;

use App\Models\RequestLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestLog>
 */
class RequestLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'host' => 'shop.tunnels.example',
            'method' => 'GET',
            'path' => '/'.fake()->slug(2),
            'status' => 200,
            'duration_ms' => fake()->randomFloat(2, 1, 300),
            'request_bytes' => 0,
            'response_bytes' => fake()->numberBetween(100, 5000),
            'remote' => '203.0.113.0',
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 50)),
        ];
    }

    public function forMember(User $user, Team $team): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'team_id' => $team->id]);
    }
}
