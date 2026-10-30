<?php

namespace Database\Factories;

use App\Enums\MatchSource;
use App\Enums\MatchState;
use App\Enums\Side;
use App\Models\Fixture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fixture>
 */
class FixtureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => MatchSource::Sim,
            'game' => 'cs-go',
            'game_name' => 'Counter-Strike',
            'tournament' => fake()->words(3, true),
            'team_a' => fake()->unique()->company(),
            'team_b' => fake()->unique()->company(),
            'odds_a' => '1.85',
            'odds_b' => '1.95',
            'starts_at' => now()->addHour(),
            'state' => MatchState::Upcoming,
        ];
    }

    public function live(): static
    {
        return $this->state(['state' => MatchState::Live, 'starts_at' => now()->subMinutes(20)]);
    }

    public function finished(Side $winner = Side::A): static
    {
        return $this->state(['state' => MatchState::Finished, 'winner' => $winner, 'starts_at' => now()->subHours(2)]);
    }
}
