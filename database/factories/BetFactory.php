<?php

namespace Database\Factories;

use App\Enums\BetStatus;
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
            'fixture_id' => Fixture::factory(),
            'side' => Side::A,
            'stake' => 100,
            'odds' => '1.85',
            'payout' => 185,
            'status' => BetStatus::Pending,
        ];
    }
}
