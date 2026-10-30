<?php

namespace Tests\Feature\Services;

use App\Enums\BetStatus;
use App\Enums\Role;
use App\Enums\Side;
use App\Models\Bet;
use App\Models\BetSelection;
use App\Models\Fixture;
use App\Services\BettingService;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_winners_are_paid_and_losers_are_not(): void
    {
        $fixture = Fixture::factory()->create(['odds_a' => '1.85', 'odds_b' => '1.95']);
        $winner = $this->userWithRole(Role::Customer);
        $loser = $this->userWithRole(Role::Customer);
        $betting = app(BettingService::class);
        $betting->place($winner, $fixture, Side::A, 100);
        $betting->place($loser, $fixture, Side::B, 200);

        $fixture->update(['state' => 'finished', 'winner' => Side::A]);
        $settled = app(SettlementService::class)->settle($fixture);

        $this->assertSame(2, $settled);
        $this->assertSame(1000 - 100 + 185, $winner->wallet->refresh()->balance);
        $this->assertSame(1000 - 200, $loser->wallet->refresh()->balance);
        $this->assertSame([BetStatus::Won, BetStatus::Lost], Bet::orderBy('id')->pluck('status')->all());
        $this->assertNotNull(Bet::first()->settled_at);
    }

    public function test_settling_twice_pays_once(): void
    {
        $fixture = Fixture::factory()->create(['odds_a' => '2.00']);
        $customer = $this->userWithRole(Role::Customer);
        app(BettingService::class)->place($customer, $fixture, Side::A, 100);
        $fixture->update(['state' => 'finished', 'winner' => Side::A]);

        app(SettlementService::class)->settle($fixture);
        $this->assertSame(0, app(SettlementService::class)->settle($fixture));

        $this->assertSame(1100, $customer->wallet->refresh()->balance);
    }

    public function test_a_combo_waits_for_every_pick_and_pays_once_all_have_won(): void
    {
        $first = Fixture::factory()->create(['odds_a' => '2.00']);
        $second = Fixture::factory()->create(['odds_b' => '1.50']);
        $customer = $this->userWithRole(Role::Customer);
        app(BettingService::class)->placeCombo($customer, [
            ['matchId' => $first->id, 'side' => Side::A],
            ['matchId' => $second->id, 'side' => Side::B],
        ], 100);

        $first->update(['state' => 'finished', 'winner' => Side::A]);
        $this->assertSame(0, app(SettlementService::class)->settle($first));
        $this->assertSame(BetStatus::Pending, Bet::sole()->status);
        $this->assertSame([BetStatus::Won, BetStatus::Pending], BetSelection::orderBy('id')->pluck('status')->all());

        $second->update(['state' => 'finished', 'winner' => Side::B]);
        $this->assertSame(1, app(SettlementService::class)->settle($second));
        $this->assertSame(BetStatus::Won, Bet::sole()->status);
        $this->assertSame(1000 - 100 + 300, $customer->wallet->refresh()->balance);
    }

    public function test_a_combo_is_lost_as_soon_as_one_pick_loses(): void
    {
        $first = Fixture::factory()->create();
        $second = Fixture::factory()->create();
        $customer = $this->userWithRole(Role::Customer);
        app(BettingService::class)->placeCombo($customer, [
            ['matchId' => $first->id, 'side' => Side::A],
            ['matchId' => $second->id, 'side' => Side::A],
        ], 100);

        $first->update(['state' => 'finished', 'winner' => Side::B]);
        $this->assertSame(1, app(SettlementService::class)->settle($first));
        $this->assertSame(BetStatus::Lost, Bet::sole()->status);

        // The other pick still settles later, but never pays.
        $second->update(['state' => 'finished', 'winner' => Side::A]);
        $this->assertSame(0, app(SettlementService::class)->settle($second));
        $this->assertSame(BetStatus::Won, BetSelection::where('fixture_id', $second->id)->value('status'));
        $this->assertSame(900, $customer->wallet->refresh()->balance);
    }

    public function test_a_match_without_a_result_cannot_be_settled(): void
    {
        $this->expectException(LogicException::class);

        app(SettlementService::class)->settle(Fixture::factory()->live()->create());
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public static function payouts(): array
    {
        return [
            'exact' => [100, '1.85', 185],
            'rounds half up' => [3, '1.55', 5],
            'rounds down below half' => [7, '1.15', 8],
            'large stake' => [1_000_000, '999.00', 999_000_000],
        ];
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function totals(): array
    {
        return [
            'one pick' => [['1.85'], '1.85'],
            'rounds half up per pick' => [['1.85', '2.10', '1.50'], '5.84'],
            'capped' => [array_fill(0, 20, '15.00'), '1000000.00'],
        ];
    }

    /**
     * @param  list<string>  $odds
     */
    #[DataProvider('totals')]
    public function test_total_odds_multiply_the_picks(array $odds, string $expected): void
    {
        $this->assertSame($expected, Bet::totalOdds($odds));
    }

    #[DataProvider('payouts')]
    public function test_payout_is_stake_times_odds_rounded(int $stake, string $odds, int $expected): void
    {
        $this->assertSame($expected, Bet::payoutFor($stake, $odds));
    }
}
