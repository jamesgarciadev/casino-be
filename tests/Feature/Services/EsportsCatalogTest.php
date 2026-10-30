<?php

namespace Tests\Feature\Services;

use App\Services\PandaScore\EsportsCatalog;
use App\Services\PandaScore\PandaScoreUnavailable;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPandaScore;
use Tests\TestCase;

class EsportsCatalogTest extends TestCase
{
    use FakesPandaScore;

    private function catalog(): EsportsCatalog
    {
        return app(EsportsCatalog::class);
    }

    private function fakeMatches(string $window, array $matches, int $total = 1): void
    {
        Http::fake([self::PANDASCORE."/matches/{$window}*" => Http::response($matches, 200, ['X-Total' => (string) $total])]);
    }

    public function test_matches_are_normalized_to_the_api_shape(): void
    {
        $this->fakeMatches('past', [$this->matchFixture(['begin_at' => '2025-06-24T21:00:00Z'])]);

        $match = $this->catalog()->matches('past', null, 1, 20)['data'][0];

        $this->assertSame([
            'id' => '1200600',
            'name' => 'Quarterfinal 1: BST.A vs RED.A',
            'status' => 'finished',
            'matchType' => 'best_of',
            'numberOfGames' => 3,
            'scheduledAt' => null,
            'beginAt' => '2025-06-24T21:00:00+00:00',
            'endAt' => null,
            'game' => ['id' => '3', 'name' => 'Counter-Strike', 'slug' => 'cs-go'],
            'league' => [
                'id' => '4554',
                'name' => 'Gamers Club Liga Série A',
                'imageUrl' => 'https://cdn-api.pandascore.co/images/league/image/4554/Liga_Gamers_Club_SSrie_A_logo.png',
            ],
            'tournament' => 'Playoffs',
            'serie' => 'June 2025',
            'opponents' => [
                ['id' => '135743', 'type' => 'TEAM', 'name' => 'BESTIA Academy', 'acronym' => 'BST.A',
                    'imageUrl' => 'https://cdn-api.pandascore.co/images/team/image/135743/186px_bestia_lightmode.png'],
                ['id' => '135505', 'type' => 'TEAM', 'name' => 'RED Canids Academy', 'acronym' => 'RED.A',
                    'imageUrl' => 'https://cdn-api.pandascore.co/images/team/image/135505/red_canids_cs_go.png'],
            ],
            'scores' => [['opponentId' => '135743', 'score' => 0], ['opponentId' => '135505', 'score' => 2]],
            'winnerId' => '135505',
            'streams' => [[
                'url' => 'https://www.twitch.tv/gamersclubes',
                'embedUrl' => 'https://player.twitch.tv/?channel=gamersclubes',
                'language' => 'es',
                'main' => false,
                'official' => false,
            ]],
        ], $match);
    }

    public function test_player_matches_and_missing_optional_fields_are_handled(): void
    {
        $this->fakeMatches('upcoming', [$this->matchFixture([
            'status' => 'not_started',
            'league' => null,
            'winner_id' => null,
            'streams_list' => [],
            'opponents' => [['type' => 'Player', 'opponent' => ['id' => 7, 'name' => 'Serral', 'image_url' => null]]],
            'results' => [['player_id' => 7, 'score' => 0]],
        ])]);

        $match = $this->catalog()->matches('upcoming', null, 1, 20)['data'][0];

        $this->assertNull($match['league']);
        $this->assertNull($match['winnerId']);
        $this->assertSame([], $match['streams']);
        $this->assertSame(['id' => '7', 'type' => 'PLAYER', 'name' => 'Serral', 'acronym' => null, 'imageUrl' => null], $match['opponents'][0]);
        $this->assertSame([['opponentId' => '7', 'score' => 0]], $match['scores']);
    }

    public function test_page_info_is_derived_from_the_total(): void
    {
        $this->fakeMatches('past', [$this->matchFixture()], total: 45);

        $this->assertSame(
            ['currentPage' => 2, 'perPage' => 20, 'total' => 45, 'hasMorePages' => true],
            $this->catalog()->matches('past', null, 2, 20)['pageInfo'],
        );
        $this->assertFalse($this->catalog()->matches('past', null, 3, 20)['pageInfo']['hasMorePages']);
    }

    public function test_repeat_reads_are_served_from_cache_until_the_ttl_expires(): void
    {
        $this->fakeMatches('running', [$this->matchFixture(['status' => 'running'])]);

        $this->catalog()->matches('running', null, 1, 20);
        $this->catalog()->matches('running', null, 1, 20);
        Http::assertSentCount(1);

        $this->travel(16)->seconds();
        $this->catalog()->matches('running', null, 1, 20);
        Http::assertSentCount(2);
    }

    public function test_each_window_game_and_page_is_cached_separately(): void
    {
        $this->fakeVideogames();
        Http::fake([self::PANDASCORE.'/matches/*' => Http::response([], 200, ['X-Total' => '0'])]);

        $this->catalog()->matches('upcoming', null, 1, 20);
        $this->catalog()->matches('upcoming', null, 2, 20);
        $this->catalog()->matches('running', null, 1, 20);
        $this->catalog()->matches('upcoming', 'cs-go', 1, 20);

        // Four match lists plus one videogames lookup to validate the slug.
        Http::assertSentCount(5);
    }

    public function test_the_last_good_copy_is_served_when_pandascore_fails(): void
    {
        Http::fake([self::PANDASCORE.'/matches/upcoming*' => Http::sequence()
            ->push([$this->matchFixture()], 200, ['X-Total' => '1'])
            ->whenEmpty(Http::response([], 503))]);

        $this->catalog()->matches('upcoming', null, 1, 20);
        $this->travel(2)->minutes();

        $this->assertSame('1200600', $this->catalog()->matches('upcoming', null, 1, 20)['data'][0]['id']);
    }

    public function test_failure_without_a_cached_copy_is_reported(): void
    {
        Http::fake([self::PANDASCORE.'/*' => Http::response([], 503)]);

        $this->expectException(PandaScoreUnavailable::class);

        $this->catalog()->matches('upcoming', null, 1, 20);
    }

    public function test_an_unknown_game_returns_nothing_without_fetching_matches(): void
    {
        $this->fakeVideogames();

        $page = $this->catalog()->matches('upcoming', 'not-a-game', 1, 20);

        $this->assertSame([], $page['data']);
        $this->assertSame(0, $page['pageInfo']['total']);
        Http::assertSentCount(1);
    }

    public function test_videogames_are_cached_for_a_day(): void
    {
        $this->fakeVideogames();

        $this->assertTrue($this->catalog()->isKnownGame('dota-2'));
        $this->assertFalse($this->catalog()->isKnownGame('chess'));
        $this->assertSame(['id' => '26', 'name' => 'Valorant', 'slug' => 'valorant'], $this->catalog()->videogames()[0]);
        Http::assertSentCount(1);
    }

    public function test_a_missing_match_is_cached_as_not_found(): void
    {
        Http::fake([self::PANDASCORE.'/matches/42' => Http::response([], 404)]);

        $this->assertNull($this->catalog()->find(42));
        $this->assertNull($this->catalog()->find(42));
        Http::assertSentCount(1);
    }

    public function test_find_returns_the_normalized_match(): void
    {
        Http::fake([self::PANDASCORE.'/matches/1200600' => Http::response($this->matchFixture())]);

        $this->assertSame('Quarterfinal 1: BST.A vs RED.A', $this->catalog()->find(1200600)['name']);
    }
}
