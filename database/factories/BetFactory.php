<?php

namespace Database\Factories;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Side;
use App\Models\Bet;
use App\Models\Fixture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bet>
 */
class BetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => BetType::Single,
            'stake' => 100,
            'odds' => '1.85',
            'payout' => 185,
            'status' => BetStatus::Pending,
        ];
    }

    /**
     * A single gets one selection on a new match, at the bet's odds and status.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Bet $bet) {
            if ($bet->type === BetType::Single && $bet->selections()->doesntExist()) {
                $bet->selections()->create([
                    'fixture_id' => Fixture::factory()->create()->id,
                    'side' => Side::A,
                    'odds' => $bet->odds,
                    'status' => $bet->status,
                ]);
            }
        });
    }
}
