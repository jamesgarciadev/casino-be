<?php

namespace Tests\Feature\GraphQL;

use App\Models\Fixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Nuwave\Lighthouse\Subscriptions\BroadcastDriverManager;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Nuwave\Lighthouse\Testing\TestsSubscriptions;
use Tests\TestCase;

class MatchUpdatedSubscriptionTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase, TestsSubscriptions;

    private function subscribe(string $arguments = ''): void
    {
        $this->graphQL("subscription { matchUpdated{$arguments} { id scoreA oddsA state } }")
            ->assertJsonPath('errors', null)
            ->assertJsonStructure(['extensions' => ['lighthouse_subscriptions' => ['channel']]]);
    }

    private function updateMatch(Fixture $fixture, string $input): void
    {
        $this->withToken($this->simToken())
            ->graphQL("mutation { simUpdateMatch(id: \"{$fixture->id}\", input: {$input}) { id } }")
            ->assertJsonPath('errors', null);
    }

    /**
     * @return list<array{0: Subscriber, 1: mixed}>
     */
    private function broadcasts(): array
    {
        $broadcasts = [];
        app(BroadcastDriverManager::class)->driver()
            ->shouldHaveReceived('broadcast')
            ->withArgs(function (Subscriber $subscriber, mixed $data) use (&$broadcasts) {
                $broadcasts[] = [$subscriber, $data];

                return true;
            });

        return $broadcasts;
    }

    public function test_anyone_can_subscribe_and_receives_sim_updates(): void
    {
        $fixture = Fixture::factory()->live()->create();
        $this->subscribe();

        $this->updateMatch($fixture, '{ scoreA: 4, oddsA: 1.40 }');

        [[, $data]] = $this->broadcasts();
        $this->assertSame(['id' => (string) $fixture->id, 'scoreA' => 4, 'oddsA' => 1.4, 'state' => 'LIVE'], $data['data']['matchUpdated']);
    }

    public function test_game_filter_skips_other_games(): void
    {
        $cs = Fixture::factory()->live()->create(['game' => 'cs-go']);
        $this->subscribe('(game: "dota-2")');

        $this->updateMatch($cs, '{ scoreA: 1 }');

        app(BroadcastDriverManager::class)->driver()->shouldNotHaveReceived('broadcast');
    }

    public function test_id_filter_delivers_only_that_match(): void
    {
        [$watched, $other] = Fixture::factory()->live()->count(2)->create();
        $this->subscribe("(id: \"{$watched->id}\")");

        $this->updateMatch($other, '{ scoreA: 1 }');
        app(BroadcastDriverManager::class)->driver()->shouldNotHaveReceived('broadcast');

        $this->updateMatch($watched, '{ scoreA: 2 }');
        [[, $data]] = $this->broadcasts();
        $this->assertSame((string) $watched->id, $data['data']['matchUpdated']['id']);
    }

    public function test_a_match_page_subscriber_receives_the_latest_market_odds(): void
    {
        $fixture = Fixture::factory()->live()->create();
        $this->graphQL("subscription { matchUpdated(id: \"{$fixture->id}\") { markets(status: [OPEN, SUSPENDED]) { key outcomes { key odds } } } }")
            ->assertJsonPath('errors', null);
        $pistol = fn (float $oddsA, float $oddsB) => [
            'key' => 'map1.pistol.1', 'type' => 'PISTOL_ROUND', 'map' => 1, 'round' => 1, 'status' => 'OPEN',
            'outcomes' => [['key' => 'a', 'odds' => $oddsA], ['key' => 'b', 'odds' => $oddsB]],
        ];
        $update = 'mutation ($id: ID!, $input: SimUpdateMatchInput!) { simUpdateMatch(id: $id, input: $input) { id } }';

        $this->withToken($this->simToken())->graphQL($update, ['id' => $fixture->id, 'input' => ['markets' => [$pistol(1.90, 1.85)]]])
            ->assertJsonPath('errors', null);
        $this->withToken($this->simToken())->graphQL($update, ['id' => $fixture->id, 'input' => ['markets' => [$pistol(1.30, 3.20)]]])
            ->assertJsonPath('errors', null);

        $pushed = array_map(fn (array $broadcast) => $broadcast[1]['data']['matchUpdated']['markets'][1]['outcomes'] ?? null, $this->broadcasts());
        $this->assertSame([
            [['key' => 'a', 'odds' => 1.9], ['key' => 'b', 'odds' => 1.85]],
            [['key' => 'a', 'odds' => 1.3], ['key' => 'b', 'odds' => 3.2]],
        ], $pushed);
    }

    public function test_a_busy_match_page_push_fits_in_what_reverb_accepts(): void
    {
        $fixture = Fixture::factory()->live()->create(['best_of' => 5]);
        $this->graphQL("subscription { matchUpdated(id: \"{$fixture->id}\") { id maps { name } markets(status: [OPEN, SUSPENDED]) { id key type map half round line side status outcomes { id key odds } } } }")
            ->assertJsonPath('errors', null);
        // Three times the 34 open markets of the busiest live CS2 series seen so far.
        $markets = array_map(fn (int $round) => [
            'key' => "map5.round{$round}.winner", 'type' => 'ROUND_WINNER', 'map' => 5, 'round' => $round, 'status' => 'OPEN',
            'outcomes' => [['key' => 'a', 'odds' => 1.87], ['key' => 'b', 'odds' => 1.94]],
        ], range(1, 99));

        $this->withToken($this->simToken())
            ->graphQL('mutation ($id: ID!, $input: SimUpdateMatchInput!) { simUpdateMatch(id: $id, input: $input) { id } }', ['id' => $fixture->id, 'input' => ['markets' => $markets]])
            ->assertJsonPath('errors', null);

        [[, $data]] = $this->broadcasts();
        $this->assertCount(100, $data['data']['matchUpdated']['markets']);
        // The Pusher API sends the result JSON-encoded inside a JSON body, so it is measured encoded twice.
        $this->assertLessThan(config('reverb.servers.reverb.max_request_size'), strlen(json_encode(['data' => json_encode($data)])));
    }

    public function test_a_malformed_id_cannot_subscribe(): void
    {
        $this->graphQL('subscription { matchUpdated(id: "not-a-match") { id } }')
            ->assertJsonPath('errors.0.message', 'Validation failed for the field [matchUpdated].');
    }

    public function test_created_matches_are_pushed_too(): void
    {
        $this->subscribe();

        $this->withToken($this->simToken())->graphQL(/** @lang GraphQL */ '
            mutation {
                simCreateMatch(input: {
                    game: "cs-go", gameName: "Counter-Strike", tournament: "Sim Cup", teamA: "NAVI", teamB: "FaZe",
                    oddsA: 1.8, oddsB: 2.0, startsAt: "2026-10-30T12:00:00+00:00"
                }) { id }
            }
        ')->assertJsonPath('errors', null);

        [[, $data]] = $this->broadcasts();
        $this->assertSame('UPCOMING', $data['data']['matchUpdated']['state']);
    }

    public function test_rejected_updates_are_not_pushed(): void
    {
        $fixture = Fixture::factory()->finished()->create();
        $this->subscribe();

        $this->withToken($this->simToken())
            ->graphQL("mutation { simUpdateMatch(id: \"{$fixture->id}\", input: { scoreA: 1 }) { id } }")
            ->assertGraphQLErrorMessage('Match is already finished.');

        app(BroadcastDriverManager::class)->driver()->shouldNotHaveReceived('broadcast', [Mockery::any(), Mockery::any()]);
    }
}
