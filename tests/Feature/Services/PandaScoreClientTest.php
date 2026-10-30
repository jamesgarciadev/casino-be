<?php

namespace Tests\Feature\Services;

use App\Services\PandaScore\PandaScoreClient;
use App\Services\PandaScore\PandaScoreUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPandaScore;
use Tests\TestCase;

class PandaScoreClientTest extends TestCase
{
    use FakesPandaScore;

    private function client(): PandaScoreClient
    {
        return app(PandaScoreClient::class);
    }

    public function test_requests_carry_the_bearer_token_and_ask_for_json(): void
    {
        $this->fakeVideogames();

        $games = $this->client()->videogames();

        $this->assertSame('cs-go', $games[2]['slug']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request->url() === self::PANDASCORE.'/videogames?per_page=100');
    }

    public function test_matches_passes_window_page_and_game_filter_and_reads_the_total(): void
    {
        Http::fake([self::PANDASCORE.'/matches/upcoming*' => Http::response([$this->matchFixture()], 200, ['X-Total' => '244'])]);

        $result = $this->client()->matches('upcoming', 'cs-go', 3, 10);

        $this->assertSame(244, $result['total']);
        $this->assertSame(1200600, $result['items'][0]['id']);
        Http::assertSent(fn (Request $request) => $request->url() === self::PANDASCORE
            .'/matches/upcoming?page=3&per_page=10&'.urlencode('filter[videogame]').'=cs-go');
    }

    public function test_matches_without_a_game_sends_no_filter(): void
    {
        Http::fake([self::PANDASCORE.'/matches/running*' => Http::response([], 200, ['X-Total' => '0'])]);

        $this->client()->matches('running', null, 1, 20);

        Http::assertSent(fn (Request $request) => ! str_contains(urldecode($request->url()), 'filter'));
    }

    public function test_an_unknown_match_id_returns_null(): void
    {
        Http::fake([self::PANDASCORE.'/matches/42' => Http::response(['error' => 'Not found'], 404)]);

        $this->assertNull($this->client()->match(42));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failingStatuses(): array
    {
        return ['bad token' => [401], 'plan lacks endpoint' => [403], 'rate limited' => [429], 'server error' => [500]];
    }

    #[DataProvider('failingStatuses')]
    public function test_error_responses_become_unavailable(int $status): void
    {
        Http::fake([self::PANDASCORE.'/*' => Http::response(['error' => 'nope'], $status)]);

        $this->expectException(PandaScoreUnavailable::class);

        $this->client()->videogames();
    }

    public function test_a_404_outside_a_match_lookup_is_an_error(): void
    {
        Http::fake([self::PANDASCORE.'/*' => Http::response([], 404)]);

        $this->expectException(PandaScoreUnavailable::class);

        $this->client()->matches('upcoming', null, 1, 20);
    }

    public function test_connection_failures_become_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(PandaScoreUnavailable::class);

        $this->client()->videogames();
    }

    public function test_server_errors_are_retried(): void
    {
        Http::fake([self::PANDASCORE.'/*' => Http::sequence()
            ->push([], 502)
            ->push($this->fixture('videogames'))]);

        $this->assertCount(4, $this->client()->videogames());
        Http::assertSentCount(2);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake([self::PANDASCORE.'/*' => Http::response([], 429)]);

        try {
            $this->client()->videogames();
        } catch (PandaScoreUnavailable) {
        }

        Http::assertSentCount(1);
    }

    public function test_a_missing_token_fails_without_calling_pandascore(): void
    {
        config(['services.pandascore.token' => null]);
        Http::fake();

        try {
            $this->client()->videogames();
            $this->fail('Expected PandaScoreUnavailable.');
        } catch (PandaScoreUnavailable $e) {
            $this->assertSame('PANDASCORE_API_TOKEN is not set', $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_the_client_facing_message_hides_upstream_detail(): void
    {
        Http::fake([self::PANDASCORE.'/*' => Http::response([], 401)]);

        try {
            $this->client()->videogames();
        } catch (PandaScoreUnavailable $e) {
            $this->assertTrue($e->isClientSafe());
            $this->assertSame('Esports data is temporarily unavailable. Try again shortly.', $e->getMessage());
            $this->assertStringContainsString('HTTP 401', $e->reason);
        }
    }
}
