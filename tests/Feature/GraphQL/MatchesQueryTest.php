<?php

namespace Tests\Feature\GraphQL;

use App\Enums\MatchState;
use App\Enums\Side;
use App\Models\Fixture;
use Database\Seeders\FixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MatchesQueryTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    public function test_matches_are_public_and_list_live_first_then_by_start_time(): void
    {
        $later = Fixture::factory()->create(['starts_at' => now()->addHours(3)]);
        $sooner = Fixture::factory()->create(['starts_at' => now()->addHour()]);
        $live = Fixture::factory()->live()->create();
        Fixture::factory()->finished()->create();

        $this->graphQL('{ matches { id state } }')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.matches', [
                ['id' => (string) $live->id, 'state' => 'LIVE'],
                ['id' => (string) $sooner->id, 'state' => 'UPCOMING'],
                ['id' => (string) $later->id, 'state' => 'UPCOMING'],
            ]);
    }

    public function test_matches_can_be_filtered_by_state_and_game(): void
    {
        Fixture::factory()->live()->create(['game' => 'cs-go']);
        $dota = Fixture::factory()->live()->create(['game' => 'dota-2']);
        Fixture::factory()->create(['game' => 'dota-2']);
        $finished = Fixture::factory()->finished(Side::B)->create();

        $this->graphQL('{ matches(state: LIVE, game: "dota-2") { id } }')
            ->assertJsonPath('data.matches', [['id' => (string) $dota->id]]);

        $this->graphQL('{ matches(state: FINISHED) { id winner } }')
            ->assertJsonPath('data.matches', [['id' => (string) $finished->id, 'winner' => 'B']]);
    }

    public function test_match_fields(): void
    {
        $fixture = Fixture::factory()->live()->create([
            'game' => 'valorant', 'game_name' => 'Valorant', 'tournament' => 'VCT', 'team_a' => 'Sentinels', 'team_b' => 'Fnatic',
            'team_a_acronym' => 'SEN', 'score_a' => 7, 'score_b' => 5, 'odds_a' => '1.62', 'odds_b' => '2.30',
            'stream_url' => 'https://twitch.tv/valorant',
        ]);

        $this->graphQL(/** @lang GraphQL */ '
            query ($id: ID!) {
                match(id: $id) {
                    game gameName tournament teamA teamB teamAAcronym teamBAcronym scoreA scoreB oddsA oddsB state winner streamUrl
                }
            }
        ', ['id' => $fixture->id])->assertJsonPath('data.match', [
            'game' => 'valorant', 'gameName' => 'Valorant', 'tournament' => 'VCT', 'teamA' => 'Sentinels', 'teamB' => 'Fnatic',
            'teamAAcronym' => 'SEN', 'teamBAcronym' => null, 'scoreA' => 7, 'scoreB' => 5, 'oddsA' => 1.62, 'oddsB' => 2.3,
            'state' => 'LIVE', 'winner' => null, 'streamUrl' => 'https://twitch.tv/valorant',
        ]);
    }

    public function test_unknown_match_is_null(): void
    {
        $this->graphQL('{ match(id: "999") { id } }')->assertJsonPath('data.match', null);
    }

    public function test_game_filter_is_validated(): void
    {
        $this->graphQL('{ matches(game: "../etc") { id } }')
            ->assertGraphQLErrorMessage('Validation failed for the field [matches].');
    }

    public function test_seeded_design_fixtures_appear_with_two_live(): void
    {
        $this->seed(FixtureSeeder::class);
        $this->seed(FixtureSeeder::class);

        $this->assertDatabaseCount('fixtures', 8);
        $this->assertSame(2, Fixture::where('state', MatchState::Live)->count());
        $this->graphQL('{ matches { teamA } }')->assertJsonPath('data.matches.0.teamA', 'Vitality');
    }
}
