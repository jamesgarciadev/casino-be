<?php

namespace Tests\Feature\GraphQL;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPandaScore;
use Tests\TestCase;

class EsportsQueryTest extends TestCase
{
    use FakesPandaScore, MakesGraphQLRequests;

    public function test_videogames_are_public(): void
    {
        $this->fakeVideogames();

        $this->graphQL('{ videogames { id name slug } }')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.videogames.2', ['id' => '3', 'name' => 'Counter-Strike', 'slug' => 'cs-go']);
    }

    public function test_esports_matches_returns_a_page_of_matches(): void
    {
        Http::fake([self::PANDASCORE.'/matches/past*' => Http::response(
            [$this->matchFixture(['begin_at' => '2025-06-24T21:00:00Z'])], 200, ['X-Total' => '21'],
        )]);

        $this->graphQL(/** @lang GraphQL */ '
            {
                esportsMatches(window: PAST, perPage: 1) {
                    pageInfo { currentPage perPage total hasMorePages }
                    data {
                        id name status matchType numberOfGames beginAt scheduledAt winnerId tournament serie
                        game { slug }
                        league { name }
                        opponents { id type name acronym }
                        scores { opponentId score }
                        streams { url embedUrl language main official }
                    }
                }
            }
        ')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.esportsMatches.pageInfo', ['currentPage' => 1, 'perPage' => 1, 'total' => 21, 'hasMorePages' => true])
            ->assertJsonPath('data.esportsMatches.data.0.status', 'FINISHED')
            ->assertJsonPath('data.esportsMatches.data.0.beginAt', '2025-06-24T21:00:00+00:00')
            ->assertJsonPath('data.esportsMatches.data.0.scheduledAt', null)
            ->assertJsonPath('data.esportsMatches.data.0.game.slug', 'cs-go')
            ->assertJsonPath('data.esportsMatches.data.0.opponents.1', ['id' => '135505', 'type' => 'TEAM', 'name' => 'RED Canids Academy', 'acronym' => 'RED.A'])
            ->assertJsonPath('data.esportsMatches.data.0.winnerId', '135505');
    }

    public function test_window_defaults_to_upcoming_and_game_is_forwarded(): void
    {
        $this->fakeVideogames();
        Http::fake([self::PANDASCORE.'/matches/*' => Http::response([], 200, ['X-Total' => '0'])]);

        $this->graphQL('{ esportsMatches(game: "dota-2") { pageInfo { total } } }')->assertJsonPath('errors', null);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::PANDASCORE.'/matches/upcoming')
            && ($request['filter[videogame]'] ?? null) === 'dota-2');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'game with a path separator' => ['game: "../videogames"'],
            'game with uppercase' => ['game: "CS-GO"'],
            'page zero' => ['page: 0'],
            'perPage over 50' => ['perPage: 51'],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_are_rejected_before_calling_pandascore(string $arguments): void
    {
        Http::fake();

        $this->graphQL("{ esportsMatches({$arguments}) { pageInfo { total } } }")
            ->assertGraphQLErrorMessage('Validation failed for the field [esportsMatches].');

        Http::assertNothingSent();
    }

    public function test_esports_match_by_id(): void
    {
        Http::fake([
            self::PANDASCORE.'/matches/1200600' => Http::response($this->matchFixture()),
            self::PANDASCORE.'/matches/7' => Http::response([], 404),
        ]);

        $this->graphQL('{ found: esportsMatch(id: "1200600") { name } missing: esportsMatch(id: "7") { name } }')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.found.name', 'Quarterfinal 1: BST.A vs RED.A')
            ->assertJsonPath('data.missing', null);
    }

    public function test_esports_match_id_must_be_numeric(): void
    {
        Http::fake();

        $this->graphQL('{ esportsMatch(id: "1/../../videogames") { name } }')
            ->assertGraphQLErrorMessage('Validation failed for the field [esportsMatch].');

        Http::assertNothingSent();
    }

    public function test_pandascore_outage_returns_a_safe_error(): void
    {
        Http::fake([self::PANDASCORE.'/*' => Http::response(['error' => 'Invalid token'], 401)]);

        $this->graphQL('{ esportsMatches { pageInfo { total } } }')
            ->assertGraphQLErrorMessage('Esports data is temporarily unavailable. Try again shortly.')
            ->assertDontSee('Invalid token')
            ->assertDontSee('test-token');
    }

    public function test_esports_queries_are_limited_to_60_per_minute_per_ip(): void
    {
        $this->fakeVideogames();

        foreach (range(1, 60) as $attempt) {
            $this->graphQL('{ videogames { slug } }');
        }

        $this->graphQL('{ videogames { slug } }')
            ->assertGraphQLErrorMessage('Rate limit for Query.videogames exceeded. Try again later.');
    }
}
