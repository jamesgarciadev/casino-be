<?php

namespace Tests\Feature\Seeders;

use App\Enums\MarketStatus;
use App\Enums\MatchState;
use App\Enums\Side;
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
        Fixture::where('team_a', 'NAVI')->sole()->update(['odds_a' => '9.00']);
        Model::withoutEvents(fn () => $this->seed(FixtureSeeder::class));

        $this->assertSame(8, Fixture::count());
        $this->assertSame(0, Fixture::doesntHave('winnerMarket')->count());

        $navi = Fixture::where('team_a', 'NAVI')->sole();
        $this->assertSame(['1.85', '1.95'], $navi->winnerMarket->outcomes->pluck('odds')->all());
    }

    public function test_a_re_seed_reopens_the_market_of_a_finished_match(): void
    {
        $this->seed(FixtureSeeder::class);
        $navi = Fixture::where('team_a', 'NAVI')->sole();
        $navi->update(['state' => MatchState::Finished, 'winner' => Side::A]);
        $this->assertSame(MarketStatus::Settled, $navi->winnerMarket->status);

        $this->seed(FixtureSeeder::class);

        $market = $navi->refresh()->winnerMarket;
        $this->assertSame(MarketStatus::Open, $market->status);
        $this->assertSame([null, null], $market->outcomes->pluck('result')->all());
    }
}
