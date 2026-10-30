<?php

namespace Tests\Live;

use App\Services\PandaScore\EsportsCatalog;
use App\Services\PandaScore\PandaScoreClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract tests against the real PandaScore API, using the token in .env.
 * Not part of `composer test`; run with `composer test:live` (~25 requests of the hourly quota).
 * They fail when PandaScore renames or drops a field we rely on.
 */
class PandaScoreLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (blank(config('services.pandascore.token'))) {
            $this->markTestSkipped('PANDASCORE_API_TOKEN is not set.');
        }
    }

    /**
     * Every list endpoint our plan can use, with a field each item must have.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function endpoints(): array
    {
        return [
            'videogames' => ['/videogames', ['id', 'name', 'slug']],
            'leagues' => ['/leagues', ['id', 'name', 'slug', 'videogame']],
            'series' => ['/series', ['id', 'full_name', 'league_id']],
            'tournaments' => ['/tournaments', ['id', 'name', 'serie_id', 'league_id']],
            'teams' => ['/teams', ['id', 'name', 'acronym', 'image_url']],
            'players' => ['/players', ['id', 'name']],
            'matches' => ['/matches', ['id', 'name', 'status', 'videogame', 'opponents']],
            'matches upcoming' => ['/matches/upcoming', ['id', 'status', 'scheduled_at']],
            'matches running' => ['/matches/running', ['id', 'status', 'begin_at']],
            'matches past' => ['/matches/past', ['id', 'status', 'results', 'winner_id']],
            'lives' => ['/lives', ['match']],
            'cs matches' => ['/csgo/matches', ['id', 'videogame']],
            'lol matches' => ['/lol/matches', ['id', 'videogame']],
            'dota 2 matches' => ['/dota2/matches', ['id', 'videogame']],
            'valorant matches' => ['/valorant/matches', ['id', 'videogame']],
            'cs tournaments' => ['/csgo/tournaments', ['id', 'name']],
            'lol champions' => ['/lol/champions', ['id', 'name']],
            'cs maps' => ['/csgo/maps', ['id', 'name']],
        ];
    }

    /**
     * @param  list<string>  $fields
     */
    #[DataProvider('endpoints')]
    public function test_endpoint_is_available(string $path, array $fields): void
    {
        $response = Http::baseUrl(config('services.pandascore.base_url'))
            ->withToken(config('services.pandascore.token'))
            ->acceptJson()
            ->get($path, ['per_page' => 1]);

        $this->assertSame(200, $response->status(), "{$path} returned {$response->status()}");
        $this->assertIsList($response->json());

        // Running matches and lives can legitimately be empty between events.
        if ($response->json() === []) {
            $this->markTestIncomplete("{$path} returned no items right now; shape not checked.");
        }

        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $response->json(0), "{$path} items lost `{$field}`");
        }
    }

    public function test_odds_are_not_part_of_this_plan(): void
    {
        // Documents the current plan. If this starts failing, odds became available.
        $response = Http::baseUrl(config('services.pandascore.base_url'))
            ->withToken(config('services.pandascore.token'))
            ->get('/odds');

        $this->assertSame(404, $response->status());
    }

    public function test_client_reads_every_match_window(): void
    {
        $client = app(PandaScoreClient::class);

        foreach (['upcoming', 'running', 'past'] as $window) {
            $result = $client->matches($window, null, 1, 1);

            $this->assertIsInt($result['total']);
            $this->assertLessThanOrEqual(1, count($result['items']));
        }

        $this->assertGreaterThan(0, $client->matches('past', null, 1, 1)['total']);
    }

    public function test_game_filter_narrows_results(): void
    {
        $client = app(PandaScoreClient::class);

        $all = $client->matches('past', null, 1, 1)['total'];
        $cs = $client->matches('past', 'cs-go', 1, 5);

        $this->assertGreaterThan(0, $cs['total']);
        $this->assertLessThan($all, $cs['total']);
        $this->assertSame(['cs-go'], array_values(array_unique(array_column(array_column($cs['items'], 'videogame'), 'slug'))));
    }

    public function test_catalog_normalizes_real_matches(): void
    {
        $catalog = app(EsportsCatalog::class);

        $page = $catalog->matches('past', null, 1, 5);
        $this->assertNotEmpty($page['data']);

        foreach ($page['data'] as $match) {
            $this->assertContains($match['status'], ['not_started', 'running', 'finished', 'canceled', 'postponed']);
            $this->assertNotEmpty($match['game']['slug']);
        }

        $this->assertSame($page['data'][0]['id'], $catalog->find((int) $page['data'][0]['id'])['id']);
        $this->assertNull($catalog->find(999999999));
        $this->assertTrue($catalog->isKnownGame('cs-go'));
    }
}
