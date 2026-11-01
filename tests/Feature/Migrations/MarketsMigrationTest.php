<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_01_000006_create_markets_tables.php';

    public function test_existing_matches_get_a_winner_market_and_picks_point_at_it(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        $user = DB::table('users')->insertGetId(['name' => 'P', 'email' => 'p@example.com', 'password' => 'x']);
        $fixture = fn (array $attributes) => DB::table('fixtures')->insertGetId([
            'source' => 'sim', 'game' => 'cs-go', 'game_name' => 'CS2', 'tournament' => 'T', 'team_a' => 'A', 'team_b' => 'B',
            'odds_a' => '1.80', 'odds_b' => '2.00', 'starts_at' => now(), ...$attributes,
        ]);
        $live = $fixture(['state' => 'live']);
        $finished = $fixture(['state' => 'finished', 'winner' => 'b']);
        $bet = DB::table('bets')->insertGetId(['user_id' => $user, 'stake' => 100, 'odds' => '3.60', 'payout' => 360, 'status' => 'pending']);
        DB::table('bet_selections')->insert([
            ['bet_id' => $bet, 'fixture_id' => $live, 'side' => 'a', 'odds' => '1.80', 'status' => 'pending'],
            ['bet_id' => $bet, 'fixture_id' => $finished, 'side' => 'b', 'odds' => '2.00', 'status' => 'won'],
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);

        $markets = DB::table('markets')->orderBy('fixture_id')->get(['fixture_id', 'key', 'type', 'status']);
        $this->assertEquals([
            (object) ['fixture_id' => $live, 'key' => 'winner', 'type' => 'winner', 'status' => 'open'],
            (object) ['fixture_id' => $finished, 'key' => 'winner', 'type' => 'winner', 'status' => 'settled'],
        ], $markets->all());

        $picks = DB::table('bet_selections')
            ->join('market_outcomes', 'market_outcomes.id', '=', 'bet_selections.market_outcome_id')
            ->orderBy('bet_selections.id')
            ->get(['market_outcomes.key', 'market_outcomes.odds', 'market_outcomes.result']);
        $this->assertEquals([
            (object) ['key' => 'a', 'odds' => '1.80', 'result' => null],
            (object) ['key' => 'b', 'odds' => '2.00', 'result' => 'won'],
        ], $picks->all());
    }
}
