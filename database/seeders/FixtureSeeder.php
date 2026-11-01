<?php

namespace Database\Seeders;

use App\Enums\MatchSource;
use App\Enums\MatchState;
use App\Models\Fixture;
use Illuminate\Database\Seeder;

/**
 * The design's demo matches (casino-design drizzle seed), with start times relative to now so they always
 * show as live or upcoming. Static: casino-sim provides matches that actually progress.
 */
class FixtureSeeder extends Seeder
{
    /** game slug => [PandaScore display name, tournament, team A, team B, odds A, odds B, minutes from now, stream] */
    private const MATCHES = [
        ['cs-go', 'Counter-Strike', 'BLAST Premier Fall Final', 'NAVI', 'FaZe Clan', '1.85', '1.95', 120, 'https://twitch.tv/blastpremier'],
        ['valorant', 'Valorant', 'VCT Champions 2026', 'Sentinels', 'Fnatic', '2.10', '1.72', 180, 'https://twitch.tv/valorant'],
        ['dota-2', 'Dota 2', 'The International Qualifiers', 'Team Spirit', 'Gaimin Gladiators', '1.65', '2.25', 300, 'https://twitch.tv/dota2'],
        ['league-of-legends', 'LoL', 'LCK Summer Playoffs', 'T1', 'Gen.G', '2.40', '1.58', 1560, 'https://twitch.tv/lck'],
        ['cs-go', 'Counter-Strike', 'ESL Pro League S24', 'Vitality', 'MOUZ', '1.55', '2.45', -45, 'https://twitch.tv/esl_csgo'],
        ['valorant', 'Valorant', 'VCT Pacific Stage 2', 'DRX', 'Paper Rex', '1.90', '1.90', -20, 'https://twitch.tv/valorant_pacific'],
        ['rl', 'Rocket League', 'RLCS World Championship', 'Karmine Corp', 'Team Vitality', '1.75', '2.05', 240, 'https://twitch.tv/rocketleague'],
        ['dota-2', 'Dota 2', 'DreamLeague Season 28', 'Xtreme Gaming', 'Team Falcons', '2.20', '1.67', 480, 'https://twitch.tv/dota2ti'],
    ];

    public function run(): void
    {
        foreach (self::MATCHES as [$game, $gameName, $tournament, $teamA, $teamB, $oddsA, $oddsB, $minutes, $stream]) {
            // Re-running refreshes the start times; matches with bets keep their rows.
            $fixture = Fixture::updateOrCreate(
                ['source' => MatchSource::Seed, 'tournament' => $tournament, 'team_a' => $teamA, 'team_b' => $teamB],
                [
                    'game' => $game,
                    'game_name' => $gameName,
                    'odds_a' => $oddsA,
                    'odds_b' => $oddsB,
                    'starts_at' => now()->addMinutes($minutes),
                    'state' => $minutes < 0 ? MatchState::Live : MatchState::Upcoming,
                    'score_a' => 0,
                    'score_b' => 0,
                    'winner' => null,
                    'stream_url' => $stream,
                ],
            );

            // DatabaseSeeder runs without model events, so the winner market is set up here.
            $fixture->syncWinnerMarket();
        }
    }
}
