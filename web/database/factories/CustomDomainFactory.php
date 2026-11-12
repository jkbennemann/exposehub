<?php

namespace Database\Factories;

use App\Models\CustomDomain;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomDomain>
 */
class CustomDomainFactory extends Factory
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
            'hostname' => 'app.'.fake()->unique()->domainName(),
            'verification_token' => Str::lower(Str::random(32)),
        ];
    }

    public function forMember(User $user, Team $team): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'team_id' => $team->id]);
    }

    public function verified(): static
    {
        return $this->state(fn () => ['verified_at' => now()]);
    }

    public function connected(): static
    {
        return $this->state(fn () => ['verified_at' => now(), 'routed_at' => now()]);
    }
}
