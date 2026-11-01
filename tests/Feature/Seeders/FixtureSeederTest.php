<?php

namespace Tests\Feature\Seeders;

use App\Models\Fixture;
use Database\Seeders\FixtureSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_match_has_its_winner_market_even_without_model_events(): void
    {
        // As DatabaseSeeder runs it (WithoutModelEvents), twice to cover a re-seed.
        Model::withoutEvents(fn () => $this->seed(FixtureSeeder::class));
        Model::withoutEvents(fn () => $this->seed(FixtureSeeder::class));

        $this->assertSame(8, Fixture::count());
        $this->assertSame(0, Fixture::doesntHave('winnerMarket')->count());

        $navi = Fixture::where('team_a', 'NAVI')->sole();
        $this->assertSame(['1.85', '1.95'], $navi->winnerMarket->outcomes->pluck('odds')->all());
    }
}
