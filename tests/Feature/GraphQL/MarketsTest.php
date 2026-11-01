<?php

namespace Tests\Feature\GraphQL;

use App\Enums\BetStatus;
use App\Enums\MarketStatus;
use App\Enums\Role;
use App\Enums\Side;
use App\Models\Bet;
use App\Models\Fixture;
use App\Models\Market;
use App\Models\MarketOutcome;
use App\Models\User;
use App\Services\BettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketsTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const UPDATE = /** @lang GraphQL */ '
        mutation ($id: ID!, $input: SimUpdateMatchInput!) {
            simUpdateMatch(id: $id, input: $input) { state }
        }
    ';

    private const PLACE_BET = /** @lang GraphQL */ '
        mutation ($input: PlaceBetInput!) {
            placeBet(input: $input) {
                bet { odds payout status selections { side odds status market { type map round } outcome { key } } }
                wallet { balance }
            }
        }
    ';

    private const PLACE_COMBO = /** @lang GraphQL */ '
        mutation ($selections: [BetSelectionInput!]!) {
            placeComboBet(input: { selections: $selections, stake: 100 }) { bet { status } }
        }
    ';

    private const CASH_OUT = /** @lang GraphQL */ '
        mutation ($betId: ID!, $expected: Int!) {
            cashOutBet(input: { betId: $betId, expected: $expected }) { bet { status cashOut } wallet { balance } }
        }
    ';

    private User $customer;

    private Fixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->userWithRole(Role::Customer);
        $this->fixture = Fixture::factory()->live()->create(['odds_a' => '1.80', 'odds_b' => '2.00']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function pistol(array $changes = []): array
    {
        return [
            'key' => 'map1.pistol.1', 'type' => 'PISTOL_ROUND', 'map' => 1, 'round' => 1, 'status' => 'OPEN', 'sort' => 10,
            'outcomes' => [['key' => 'a', 'odds' => 1.90], ['key' => 'b', 'odds' => 1.85]],
            ...$changes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pistolSettled(string $winner = 'a'): array
    {
        return $this->pistol(['status' => 'SETTLED', 'outcomes' => [
            ['key' => 'a', 'odds' => 1.90, 'result' => $winner === 'a' ? 'WON' : 'LOST'],
            ['key' => 'b', 'odds' => 1.85, 'result' => $winner === 'b' ? 'WON' : 'LOST'],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function totalMaps(string $status = 'OPEN'): array
    {
        return [
            'key' => 'total_maps.2.5', 'type' => 'TOTAL_MAPS', 'line' => 2.5, 'status' => $status, 'sort' => 3,
            'outcomes' => [['key' => 'over', 'odds' => 2.40], ['key' => 'under', 'odds' => 1.55]],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function feed(array $input, ?Fixture $fixture = null): TestResponse
    {
        $this->forgetAuth();

        return $this->withToken($this->simToken())
            ->graphQL(self::UPDATE, ['id' => ($fixture ?? $this->fixture)->id, 'input' => $input]);
    }

    /**
     * @param  list<array<string, mixed>>  $markets
     */
    private function open(array $markets): void
    {
        $this->feed(['markets' => $markets])->assertJsonPath('errors', null);
    }

    private function outcome(string $marketKey, string $key, ?Fixture $fixture = null): MarketOutcome
    {
        return ($fixture ?? $this->fixture)->markets()->where('key', $marketKey)->sole()->outcomes()->where('key', $key)->sole();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function placeBet(array $input): TestResponse
    {
        $this->forgetAuth();

        return $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::PLACE_BET, ['input' => ['matchId' => $this->fixture->id, 'stake' => 100, ...$input]]);
    }

    public function test_every_match_has_a_winner_market_that_follows_its_odds(): void
    {
        $this->fixture->update(['odds_a' => '1.50']);

        $this->graphQL('query ($id: ID!) { match(id: $id) { markets { key type status outcomes { key odds result } } } }', ['id' => $this->fixture->id])
            ->assertJsonPath('data.match.markets', [[
                'key' => 'winner', 'type' => 'WINNER', 'status' => 'OPEN',
                'outcomes' => [['key' => 'a', 'odds' => 1.5, 'result' => null], ['key' => 'b', 'odds' => 2, 'result' => null]],
            ]]);
    }

    public function test_the_sim_opens_markets_and_sets_maps(): void
    {
        $this->feed([
            'maps' => [['name' => 'Inferno', 'scoreA' => 13, 'scoreB' => 9, 'winner' => 'A'], ['name' => 'Anubis', 'scoreA' => 3, 'scoreB' => 1]],
            'markets' => [$this->pistol(), $this->totalMaps()],
        ])->assertJsonPath('errors', null);

        $this->graphQL('query ($id: ID!) {
            match(id: $id) {
                maps { number name scoreA scoreB winner }
                markets(status: [OPEN]) { key type map round line outcomes { key odds } }
            }
        }', ['id' => $this->fixture->id])
            ->assertJsonPath('data.match.maps', [
                ['number' => 1, 'name' => 'Inferno', 'scoreA' => 13, 'scoreB' => 9, 'winner' => 'A'],
                ['number' => 2, 'name' => 'Anubis', 'scoreA' => 3, 'scoreB' => 1, 'winner' => null],
            ])
            ->assertJsonPath('data.match.markets.0.key', 'winner')
            ->assertJsonPath('data.match.markets.1', [
                'key' => 'total_maps.2.5', 'type' => 'TOTAL_MAPS', 'map' => null, 'round' => null, 'line' => 2.5,
                'outcomes' => [['key' => 'over', 'odds' => 2.4], ['key' => 'under', 'odds' => 1.55]],
            ])
            ->assertJsonPath('data.match.markets.2.type', 'PISTOL_ROUND');
    }

    public function test_a_bet_on_an_outcome_takes_the_outcome_odds(): void
    {
        $this->open([$this->totalMaps()]);

        $this->placeBet(['outcomeId' => $this->outcome('total_maps.2.5', 'over')->id])
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.placeBet', [
                'bet' => [
                    'odds' => 2.4, 'payout' => 240, 'status' => 'PENDING',
                    'selections' => [[
                        'side' => null, 'odds' => 2.4, 'status' => 'PENDING',
                        'market' => ['type' => 'TOTAL_MAPS', 'map' => null, 'round' => null], 'outcome' => ['key' => 'over'],
                    ]],
                ],
                'wallet' => ['balance' => 900],
            ]);
    }

    public function test_a_bet_by_side_is_a_pick_on_the_winner_market(): void
    {
        $this->placeBet(['side' => 'B'])
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.placeBet.bet.selections.0.side', 'B')
            ->assertJsonPath('data.placeBet.bet.selections.0.market.type', 'WINNER')
            ->assertJsonPath('data.placeBet.bet.selections.0.outcome.key', 'b');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function closedMarkets(): array
    {
        return ['suspended' => ['SUSPENDED', 'This market is suspended.'], 'settled' => ['SETTLED', 'This market is closed.']];
    }

    #[DataProvider('closedMarkets')]
    public function test_bets_are_refused_unless_the_market_is_open(string $status, string $message): void
    {
        $this->open([$status === 'SETTLED' ? $this->pistolSettled() : $this->pistol(['status' => $status])]);

        $this->placeBet(['outcomeId' => $this->outcome('map1.pistol.1', 'a')->id])
            ->assertJsonPath('errors.0.message', $message);

        $this->assertSame(1000, $this->customer->wallet->refresh()->balance);
        $this->assertSame(0, Bet::count());
    }

    public function test_an_outcome_from_another_match_is_refused(): void
    {
        $other = Fixture::factory()->live()->create();
        $this->open([$this->totalMaps()]);

        $this->forgetAuth();
        $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::PLACE_BET, ['input' => [
                'matchId' => $other->id, 'stake' => 100, 'outcomeId' => $this->outcome('total_maps.2.5', 'over')->id,
            ]])
            ->assertJsonPath('errors.0.message', 'Outcome not found.');
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function badPicks(): array
    {
        return ['both' => [true, true], 'neither' => [false, false]];
    }

    #[DataProvider('badPicks')]
    public function test_a_pick_names_either_a_side_or_an_outcome(bool $side, bool $outcome): void
    {
        $this->open([$this->totalMaps()]);
        $input = array_filter([
            'side' => $side ? 'A' : null,
            'outcomeId' => $outcome ? $this->outcome('total_maps.2.5', 'over')->id : null,
        ]);

        $this->placeBet($input)->assertJsonPath('errors.0.message', 'Pick either a side or an outcome.');
    }

    public function test_a_combo_cannot_take_two_markets_of_one_match(): void
    {
        $this->open([$this->totalMaps()]);

        $this->forgetAuth();
        $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::PLACE_COMBO, ['selections' => [
                ['matchId' => $this->fixture->id, 'side' => 'A'],
                ['matchId' => $this->fixture->id, 'outcomeId' => $this->outcome('total_maps.2.5', 'over')->id],
            ]])
            ->assertJsonPath('errors.0.message', 'A combo can only include one pick per match.');
    }

    public function test_a_market_settled_mid_match_pays_at_once(): void
    {
        $this->open([$this->pistol()]);
        app(BettingService::class)->placeSingle($this->customer, ['matchId' => $this->fixture->id, 'outcomeId' => $this->outcome('map1.pistol.1', 'a')->id], 100);
        $loser = $this->userWithRole(Role::Customer);
        app(BettingService::class)->placeSingle($loser, ['matchId' => $this->fixture->id, 'outcomeId' => $this->outcome('map1.pistol.1', 'b')->id], 100);

        $this->feed(['scoreA' => 0, 'markets' => [$this->pistolSettled('a')]])
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.simUpdateMatch.state', 'LIVE');

        $this->assertSame([BetStatus::Won, BetStatus::Lost], Bet::orderBy('id')->pluck('status')->all());
        $this->assertSame(1000 - 100 + 190, $this->customer->wallet->refresh()->balance);
        $this->assertSame(900, $loser->wallet->refresh()->balance);
    }

    public function test_a_combo_with_a_settled_pick_waits_for_its_other_picks(): void
    {
        $other = Fixture::factory()->live()->create(['odds_a' => '1.50']);
        $this->open([$this->pistol()]);
        app(BettingService::class)->placeCombo($this->customer, [
            ['matchId' => $this->fixture->id, 'outcomeId' => $this->outcome('map1.pistol.1', 'a')->id],
            ['matchId' => $other->id, 'side' => Side::A],
        ], 100);

        $this->feed(['markets' => [$this->pistolSettled('a')]])->assertJsonPath('errors', null);
        $this->assertSame(BetStatus::Pending, Bet::sole()->status);

        $this->feed(['scoreA' => 2, 'state' => 'FINISHED', 'winner' => 'A'], $other)->assertJsonPath('errors', null);
        $this->assertSame(BetStatus::Won, Bet::sole()->status);
        // 1.90 x 1.50 = 2.85
        $this->assertSame(1000 - 100 + 285, $this->customer->wallet->refresh()->balance);
    }

    public function test_a_settled_market_never_changes(): void
    {
        $this->open([$this->pistolSettled('a')]);

        // A retried update with the same result is accepted.
        $this->feed(['markets' => [$this->pistolSettled('a')]])->assertJsonPath('errors', null);

        $this->feed(['markets' => [$this->pistolSettled('b')]])
            ->assertJsonPath('errors.0.message', 'Market map1.pistol.1 is already settled.');
        $this->feed(['markets' => [$this->pistol()]])
            ->assertJsonPath('errors.0.message', 'Market map1.pistol.1 is already settled.');
        $this->assertSame('a', Market::where('key', 'map1.pistol.1')->sole()->winningKey());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badMarkets(): array
    {
        $outcomes = [['key' => 'a', 'odds' => 1.9], ['key' => 'b', 'odds' => 1.9]];

        return [
            'winner from the feed' => [
                ['key' => 'winner', 'type' => 'WINNER', 'status' => 'OPEN', 'outcomes' => $outcomes],
                'The winner market follows oddsA, oddsB and winner.',
            ],
            'duplicate outcome keys' => [
                ['key' => 'x', 'type' => 'PISTOL_ROUND', 'status' => 'OPEN', 'outcomes' => [['key' => 'a', 'odds' => 1.9], ['key' => 'a', 'odds' => 1.9]]],
                'Market x needs at least two outcomes with different keys.',
            ],
            'two winners' => [
                ['key' => 'x', 'type' => 'PISTOL_ROUND', 'status' => 'SETTLED', 'outcomes' => [['key' => 'a', 'odds' => 1.9, 'result' => 'WON'], ['key' => 'b', 'odds' => 1.9, 'result' => 'WON']]],
                'Market x must settle with exactly one won outcome and every other lost.',
            ],
            'missing result' => [
                ['key' => 'x', 'type' => 'PISTOL_ROUND', 'status' => 'SETTLED', 'outcomes' => [['key' => 'a', 'odds' => 1.9, 'result' => 'WON'], ['key' => 'b', 'odds' => 1.9]]],
                'Market x must settle with exactly one won outcome and every other lost.',
            ],
            'result before settling' => [
                ['key' => 'x', 'type' => 'PISTOL_ROUND', 'status' => 'OPEN', 'outcomes' => [['key' => 'a', 'odds' => 1.9, 'result' => 'WON'], ['key' => 'b', 'odds' => 1.9]]],
                'Market x can only have results once it is settled.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $market
     */
    #[DataProvider('badMarkets')]
    public function test_the_feed_cannot_send_malformed_markets(array $market, string $message): void
    {
        $this->feed(['markets' => [$market]])->assertJsonPath('errors.0.message', $message);
        $this->assertSame(1, Market::count());
    }

    public function test_a_market_keeps_what_it_is_about(): void
    {
        $this->open([$this->totalMaps()]);

        $this->feed(['markets' => [[...$this->totalMaps(), 'line' => 3.5]]])
            ->assertJsonPath('errors.0.message', 'Market total_maps.2.5 cannot change what it is about.');
    }

    public function test_odds_move_and_markets_suspend(): void
    {
        $this->open([$this->pistol()]);
        $this->open([$this->pistol(['status' => 'SUSPENDED', 'outcomes' => [['key' => 'a', 'odds' => 1.30], ['key' => 'b', 'odds' => 3.20]]])]);

        $market = Market::where('key', 'map1.pistol.1')->sole();
        $this->assertSame(MarketStatus::Suspended, $market->status);
        $this->assertSame(['1.30', '3.20'], $market->outcomes->pluck('odds')->all());
    }

    public function test_a_match_cannot_finish_with_markets_unsettled(): void
    {
        $this->open([$this->totalMaps()]);

        $this->feed(['state' => 'FINISHED', 'winner' => 'A'])
            ->assertJsonPath('errors.0.message', 'Every market must be settled before the match finishes.');

        $this->feed(['state' => 'FINISHED', 'winner' => 'A', 'markets' => [[
            ...$this->totalMaps('SETTLED'),
            'outcomes' => [['key' => 'over', 'odds' => 2.4, 'result' => 'LOST'], ['key' => 'under', 'odds' => 1.55, 'result' => 'WON']],
        ]]])->assertJsonPath('data.simUpdateMatch.state', 'FINISHED');
    }

    public function test_a_market_single_cashes_out_at_its_outcome_odds(): void
    {
        $this->open([$this->totalMaps()]);
        $bet = app(BettingService::class)->placeSingle($this->customer, ['matchId' => $this->fixture->id, 'outcomeId' => $this->outcome('total_maps.2.5', 'over')->id], 100)['bet'];
        // Over is now likelier: 100 x 2.40 / 1.60 = 150.
        $this->open([[...$this->totalMaps(), 'outcomes' => [['key' => 'over', 'odds' => 1.60], ['key' => 'under', 'odds' => 2.30]]]]);

        $this->forgetAuth();
        $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::CASH_OUT, ['betId' => $bet->id, 'expected' => 150])
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.cashOutBet', ['bet' => ['status' => 'CASHED_OUT', 'cashOut' => 150], 'wallet' => ['balance' => 1050]]);
    }

    public function test_a_market_single_cannot_cash_out_while_suspended(): void
    {
        $this->open([$this->totalMaps()]);
        $bet = app(BettingService::class)->placeSingle($this->customer, ['matchId' => $this->fixture->id, 'outcomeId' => $this->outcome('total_maps.2.5', 'over')->id], 100)['bet'];
        $this->open([$this->totalMaps('SUSPENDED')]);

        $this->forgetAuth();
        $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::CASH_OUT, ['betId' => $bet->id, 'expected' => 1])
            ->assertJsonPath('errors.0.message', 'This market is suspended.');
    }

    public function test_only_the_sim_can_send_markets(): void
    {
        $this->forgetAuth();
        $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::UPDATE, ['id' => $this->fixture->id, 'input' => ['markets' => [$this->pistol()]]])
            ->assertJsonPath('errors.0.message', 'This action requires a token with the [sim] ability.');

        $this->assertSame(1, Market::count());
    }

    public function test_market_input_is_validated(): void
    {
        $this->feed(['markets' => [$this->pistol(['key' => 'Bad Key!', 'outcomes' => [['key' => 'a', 'odds' => 1.0]]])]])
            ->assertGraphQLValidationKeys(['input.markets.0.key', 'input.markets.0.outcomes', 'input.markets.0.outcomes.0.odds']);
    }
}
