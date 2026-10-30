<?php

namespace Tests\Feature\GraphQL;

use App\Enums\BetStatus;
use App\Enums\Role;
use App\Models\Bet;
use App\Models\BetSelection;
use App\Models\Fixture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BettingTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const PLACE_BET = /** @lang GraphQL */ '
        mutation ($matchId: ID!, $side: Side!, $stake: Int!) {
            placeBet(input: { matchId: $matchId, side: $side, stake: $stake }) {
                bet { type stake odds payout status selections { side odds status match { teamA } } }
                wallet { balance }
            }
        }
    ';

    private const PLACE_COMBO = /** @lang GraphQL */ '
        mutation ($selections: [BetSelectionInput!]!, $stake: Int!) {
            placeComboBet(input: { selections: $selections, stake: $stake }) {
                bet { type stake odds payout status selections { side odds match { teamA } } }
                wallet { balance }
            }
        }
    ';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->userWithRole(Role::Customer);
    }

    private function placeBet(Fixture $fixture, string $side, int $stake): TestResponse
    {
        return $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::PLACE_BET, ['matchId' => $fixture->id, 'side' => $side, 'stake' => $stake]);
    }

    public function test_wallet_opens_with_1000_credits(): void
    {
        $this->withToken($this->customerToken($this->customer))
            ->graphQL('{ wallet { balance } }')
            ->assertJsonPath('data.wallet.balance', 1000);
    }

    public function test_registration_opens_a_wallet(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation { register(input: { name: "Jane", email: "jane@example.com", password: "Secret123!", passwordConfirmation: "Secret123!" }) { token } }
        ')->assertJsonPath('errors', null);

        $this->assertDatabaseHas('wallets', ['user_id' => User::where('email', 'jane@example.com')->value('id'), 'balance' => 1000]);
    }

    public function test_placing_a_bet_debits_the_wallet_and_snapshots_the_odds(): void
    {
        $fixture = Fixture::factory()->create(['team_a' => 'NAVI', 'odds_a' => '1.85']);

        $this->placeBet($fixture, 'A', 250)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.placeBet', [
                'bet' => [
                    'type' => 'SINGLE', 'stake' => 250, 'odds' => 1.85, 'payout' => 463, 'status' => 'PENDING',
                    'selections' => [['side' => 'A', 'odds' => 1.85, 'status' => 'PENDING', 'match' => ['teamA' => 'NAVI']]],
                ],
                'wallet' => ['balance' => 750],
            ]);

        // Later odds moves don't change an existing bet.
        $fixture->update(['odds_a' => '3.00']);
        $this->assertSame('1.85', Bet::sole()->odds);
        $this->assertSame('1.85', BetSelection::sole()->odds);
    }

    /**
     * @param  list<array{matchId: int, side: string}>  $selections
     */
    private function placeCombo(array $selections, int $stake): TestResponse
    {
        return $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::PLACE_COMBO, ['selections' => $selections, 'stake' => $stake]);
    }

    public function test_a_combo_multiplies_the_odds_of_picks_from_different_matches(): void
    {
        $navi = Fixture::factory()->create(['team_a' => 'NAVI', 'odds_a' => '1.85']);
        $t1 = Fixture::factory()->live()->create(['team_a' => 'T1', 'odds_b' => '2.10']);
        $prx = Fixture::factory()->create(['team_a' => 'PRX', 'odds_a' => '1.50']);

        // 1.85 x 2.10 = 3.885 -> 3.89, x 1.50 = 5.835 -> 5.84
        $this->placeCombo([
            ['matchId' => $navi->id, 'side' => 'A'],
            ['matchId' => $t1->id, 'side' => 'B'],
            ['matchId' => $prx->id, 'side' => 'A'],
        ], 100)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.placeComboBet', [
                'bet' => [
                    'type' => 'COMBO', 'stake' => 100, 'odds' => 5.84, 'payout' => 584, 'status' => 'PENDING',
                    'selections' => [
                        ['side' => 'A', 'odds' => 1.85, 'match' => ['teamA' => 'NAVI']],
                        ['side' => 'B', 'odds' => 2.1, 'match' => ['teamA' => 'T1']],
                        ['side' => 'A', 'odds' => 1.5, 'match' => ['teamA' => 'PRX']],
                    ],
                ],
                'wallet' => ['balance' => 900],
            ]);
    }

    public function test_a_combo_cannot_back_the_same_match_twice(): void
    {
        $fixture = Fixture::factory()->create();

        $this->placeCombo([['matchId' => $fixture->id, 'side' => 'A'], ['matchId' => $fixture->id, 'side' => 'B']], 100)
            ->assertGraphQLErrorMessage('A combo can only include one pick per match.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_a_combo_needs_at_least_two_picks(): void
    {
        $this->placeCombo([['matchId' => Fixture::factory()->create()->id, 'side' => 'A']], 100)
            ->assertGraphQLErrorMessage('Validation failed for the field [placeComboBet].');
    }

    public function test_a_combo_with_a_finished_match_is_refused_whole(): void
    {
        $open = Fixture::factory()->create();
        $finished = Fixture::factory()->finished()->create(['team_a' => 'NAVI', 'team_b' => 'G2']);

        $this->placeCombo([['matchId' => $open->id, 'side' => 'A'], ['matchId' => $finished->id, 'side' => 'A']], 100)
            ->assertGraphQLErrorMessage('Betting is closed for NAVI vs G2.');

        $this->assertDatabaseCount('bets', 0);
        $this->assertDatabaseCount('bet_selections', 0);
    }

    public function test_a_combo_with_an_unknown_match_is_refused(): void
    {
        $this->placeCombo([['matchId' => Fixture::factory()->create()->id, 'side' => 'A'], ['matchId' => 999, 'side' => 'A']], 100)
            ->assertGraphQLErrorMessage('Match not found.');
    }

    public function test_bets_are_accepted_while_a_match_is_live(): void
    {
        $this->placeBet(Fixture::factory()->live()->create(), 'B', 100)->assertJsonPath('errors', null);
    }

    public function test_finished_matches_are_closed(): void
    {
        $this->placeBet(Fixture::factory()->finished()->create(), 'A', 100)
            ->assertGraphQLErrorMessage('Betting is closed for this match.');

        $this->assertDatabaseCount('bets', 0);
    }

    public function test_stake_cannot_exceed_the_balance(): void
    {
        $this->placeBet(Fixture::factory()->create(), 'A', 1001)->assertGraphQLErrorMessage('Insufficient balance.');

        $this->assertDatabaseHas('wallets', ['user_id' => $this->customer->id, 'balance' => 1000]);
        $this->assertDatabaseCount('bets', 0);
    }

    public function test_the_whole_balance_can_be_staked(): void
    {
        $this->placeBet(Fixture::factory()->create(), 'A', 1000)->assertJsonPath('data.placeBet.wallet.balance', 0);
        $this->placeBet(Fixture::factory()->create(), 'A', 1)->assertGraphQLErrorMessage('Insufficient balance.');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidStakes(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'over the maximum' => [1_000_001]];
    }

    #[DataProvider('invalidStakes')]
    public function test_invalid_stakes_are_rejected(int $stake): void
    {
        $this->placeBet(Fixture::factory()->create(), 'A', $stake)
            ->assertGraphQLErrorMessage('Validation failed for the field [placeBet].');
    }

    public function test_unknown_match_is_rejected(): void
    {
        $this->withToken($this->customerToken($this->customer))
            ->graphQL(self::PLACE_BET, ['matchId' => 999, 'side' => 'A', 'stake' => 10])
            ->assertGraphQLErrorMessage('Match not found.');
    }

    public function test_betting_requires_a_customer_token(): void
    {
        $fixture = Fixture::factory()->create();
        $variables = ['matchId' => $fixture->id, 'side' => 'A', 'stake' => 10];

        $this->graphQL(self::PLACE_BET, $variables)->assertGraphQLErrorMessage('Unauthenticated.');

        $admin = $this->userWithRole(Role::Admin);
        $this->withToken($this->adminToken($admin))->graphQL(self::PLACE_BET, $variables)
            ->assertGraphQLErrorMessage('This action requires a token with the [customer] ability.');

        $this->forgetAuth();
        $this->withToken($this->simToken())->graphQL('{ wallet { balance } }')
            ->assertGraphQLErrorMessage('This action requires a token with the [customer] ability.');
    }

    public function test_top_up_adds_500(): void
    {
        $this->withToken($this->customerToken($this->customer))
            ->graphQL('mutation { topUpWallet { balance } }')
            ->assertJsonPath('data.topUpWallet.balance', 1500);
    }

    public function test_my_bets_lists_only_my_bets_newest_first(): void
    {
        $first = Bet::factory()->for($this->customer)->create(['created_at' => now()->subHour()]);
        $second = Bet::factory()->for($this->customer)->create(['status' => BetStatus::Won]);
        Bet::factory()->create();

        $this->withToken($this->customerToken($this->customer))
            ->graphQL('{ myBets { id status } }')
            ->assertJsonPath('data.myBets', [
                ['id' => (string) $second->id, 'status' => 'WON'],
                ['id' => (string) $first->id, 'status' => 'PENDING'],
            ]);
    }
}
