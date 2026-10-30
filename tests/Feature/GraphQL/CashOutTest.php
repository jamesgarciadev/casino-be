<?php

namespace Tests\Feature\GraphQL;

use App\Enums\BetStatus;
use App\Enums\MatchState;
use App\Enums\Role;
use App\Enums\Side;
use App\Models\Bet;
use App\Models\Fixture;
use App\Models\User;
use App\Services\BettingService;
use App\Services\MatchFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashOutTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const CASH_OUT = /** @lang GraphQL */ '
        mutation ($betId: ID!, $expected: Int!) {
            cashOutBet(input: { betId: $betId, expected: $expected }) {
                bet { status cashOut selections { status } }
                wallet { balance }
            }
        }
    ';

    private User $customer;

    private Fixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->userWithRole(Role::Customer);
        $this->fixture = Fixture::factory()->live()->create(['odds_a' => '2.00', 'odds_b' => '1.80']);
    }

    /** 100 CR on team A at 2.00: wallet 900. */
    private function single(): Bet
    {
        return app(BettingService::class)->place($this->customer, $this->fixture, Side::A, 100)['bet'];
    }

    private function cashOut(Bet $bet, int $expected, ?User $as = null): TestResponse
    {
        return $this->withToken($this->customerToken($as ?? $this->customer))
            ->graphQL(self::CASH_OUT, ['betId' => $bet->id, 'expected' => $expected]);
    }

    public function test_a_single_cashes_out_at_its_fair_value_and_credits_the_wallet(): void
    {
        $bet = $this->single();
        // Team A is now the clear favourite: 100 x 2.00 / 1.25 = 160.
        $this->fixture->update(['odds_a' => '1.25']);

        $this->cashOut($bet, 160)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.cashOutBet', [
                'bet' => ['status' => 'CASHED_OUT', 'cashOut' => 160, 'selections' => [['status' => 'CASHED_OUT']]],
                'wallet' => ['balance' => 1060],
            ]);
    }

    public function test_a_higher_value_than_shown_is_paid(): void
    {
        $bet = $this->single();
        $this->fixture->update(['odds_a' => '1.60']);

        $this->cashOut($bet, 100)->assertJsonPath('data.cashOutBet.bet.cashOut', 125);
    }

    public function test_a_lower_value_than_shown_is_refused_and_nothing_changes(): void
    {
        $bet = $this->single();
        $this->fixture->update(['odds_a' => '4.00']);

        $this->cashOut($bet, 60)->assertGraphQLErrorMessage('Cash-out value changed to 50 CR.');

        $this->assertSame(BetStatus::Pending, $bet->fresh()->status);
        $this->assertSame(900, $this->customer->wallet->refresh()->balance);
    }

    public function test_combos_cannot_be_cashed_out(): void
    {
        $bet = app(BettingService::class)->placeCombo($this->customer, [
            ['matchId' => $this->fixture->id, 'side' => Side::A],
            ['matchId' => Fixture::factory()->create()->id, 'side' => Side::A],
        ], 100)['bet'];

        $this->cashOut($bet, 0)->assertGraphQLErrorMessage('Only singles can be cashed out.');
    }

    public function test_another_players_bet_is_not_found(): void
    {
        $bet = $this->single();

        $this->cashOut($bet, 0, $this->userWithRole(Role::Customer))->assertGraphQLErrorMessage('Bet not found.');
        $this->assertSame(BetStatus::Pending, $bet->fresh()->status);
    }

    public function test_a_bet_cashes_out_only_once(): void
    {
        $bet = $this->single();
        $this->cashOut($bet, 100)->assertJsonPath('errors', null);

        $this->forgetAuth();
        $this->cashOut($bet, 0)->assertGraphQLErrorMessage('This bet is already settled.');
        $this->assertSame(1000, $this->customer->wallet->refresh()->balance);
    }

    public function test_settled_bets_and_finished_matches_cannot_be_cashed_out(): void
    {
        $bet = $this->single();
        app(MatchFeedService::class)->update($this->fixture, ['state' => MatchState::Finished, 'winner' => Side::A]);

        $this->cashOut($bet, 0)->assertGraphQLErrorMessage('This bet is already settled.');
    }

    public function test_settlement_after_a_cash_out_pays_nothing_more(): void
    {
        $bet = $this->single();
        $this->cashOut($bet, 100)->assertJsonPath('data.cashOutBet.wallet.balance', 1000);

        app(MatchFeedService::class)->update($this->fixture, ['state' => MatchState::Finished, 'winner' => Side::A]);

        $this->assertSame(BetStatus::CashedOut, $bet->fresh()->status);
        $this->assertSame(1000, $this->customer->wallet->refresh()->balance);
    }

    public function test_cashed_out_bets_are_listed_as_settled(): void
    {
        $bet = $this->single();
        $this->cashOut($bet, 100);
        $this->forgetAuth();

        $this->withToken($this->customerToken($this->customer))
            ->graphQL('{ myBets(open: false) { id status cashOut } }')
            ->assertJsonPath('data.myBets', [['id' => (string) $bet->id, 'status' => 'CASHED_OUT', 'cashOut' => 100]]);
    }

    public function test_cash_out_requires_a_customer_token(): void
    {
        $bet = $this->single();

        $this->graphQL(self::CASH_OUT, ['betId' => $bet->id, 'expected' => 0])->assertGraphQLErrorMessage('Unauthenticated.');
        $this->withToken($this->adminToken($this->userWithRole(Role::Admin)))
            ->graphQL(self::CASH_OUT, ['betId' => $bet->id, 'expected' => 0])
            ->assertGraphQLErrorMessage('This action requires a token with the [customer] ability.');
    }

    /**
     * @return array<string, array{int, string, string, int}>
     */
    public static function values(): array
    {
        return [
            'odds unchanged' => [100, '2.00', '2.00', 100],
            'favourite now' => [100, '2.00', '1.25', 160],
            'underdog now' => [100, '1.50', '3.00', 50],
            'rounds half up' => [10, '1.85', '1.20', 15],
            'rounds down below half' => [7, '1.10', '1.30', 6],
        ];
    }

    #[DataProvider('values')]
    public function test_cash_out_value_is_stake_times_placed_over_current_odds(int $stake, string $placed, string $current, int $expected): void
    {
        $this->assertSame($expected, Bet::cashOutValue($stake, $placed, $current));
    }
}
