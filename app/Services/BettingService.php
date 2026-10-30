<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\MatchState;
use App\Enums\Side;
use App\Exceptions\RuleViolation;
use App\Models\Bet;
use App\Models\Fixture;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class BettingService
{
    public const MAX_STAKE = 1_000_000;

    public function __construct(private WalletService $wallets) {}

    /**
     * A single: one side of one match.
     *
     * @return array{bet: Bet, wallet: Wallet}
     */
    public function place(User $user, Fixture $fixture, Side $side, int $stake): array
    {
        return $this->placeSelections($user, [['matchId' => $fixture->id, 'side' => $side]], $stake);
    }

    /**
     * A combo: two or more picks, each from a different match. Wins only if every pick wins.
     *
     * @param  list<array{matchId: int|string, side: Side}>  $picks
     * @return array{bet: Bet, wallet: Wallet}
     */
    public function placeCombo(User $user, array $picks, int $stake): array
    {
        if (count($picks) < 2) {
            throw new RuleViolation('A combo needs picks from at least two matches.');
        }

        return $this->placeSelections($user, $picks, $stake);
    }

    /**
     * Debit the stake and record the bet in one transaction. Odds are read from the fixture rows, never from the
     * client, and the wallet row is locked so two bets can't spend the same credits.
     *
     * @param  list<array{matchId: int|string, side: Side}>  $picks
     * @return array{bet: Bet, wallet: Wallet}
     */
    private function placeSelections(User $user, array $picks, int $stake): array
    {
        if ($stake < 1 || $stake > self::MAX_STAKE) {
            throw new RuleViolation('Stake must be between 1 and '.number_format(self::MAX_STAKE).' credits.');
        }

        $sides = [];
        foreach ($picks as $pick) {
            $id = (int) $pick['matchId'];

            if (isset($sides[$id])) {
                throw new RuleViolation('A combo can only include one pick per match.');
            }

            $sides[$id] = $pick['side'];
        }

        $this->wallets->for($user);

        return DB::transaction(function () use ($user, $sides, $stake) {
            // Locked in id order so two combos sharing matches can't deadlock.
            $fixtures = Fixture::whereKey(array_keys($sides))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($fixtures->count() !== count($sides)) {
                throw new RuleViolation('Match not found.');
            }

            foreach ($fixtures as $fixture) {
                if ($fixture->state === MatchState::Finished) {
                    throw new RuleViolation(count($sides) === 1
                        ? 'Betting is closed for this match.'
                        : "Betting is closed for {$fixture->team_a} vs {$fixture->team_b}.");
                }
            }

            $wallet = Wallet::lockForUpdate()->findOrFail($user->id);

            if ($wallet->balance < $stake) {
                throw new RuleViolation('Insufficient balance.');
            }

            $wallet->decrement('balance', $stake);

            // Keep the order the picks were sent in.
            $selections = array_map(fn (int $id) => [
                'fixture_id' => $id,
                'side' => $sides[$id],
                'odds' => $fixtures[$id]->oddsFor($sides[$id]),
                'status' => BetStatus::Pending,
            ], array_keys($sides));

            $odds = Bet::totalOdds(array_column($selections, 'odds'));

            $bet = $user->bets()->create([
                'type' => count($selections) === 1 ? BetType::Single : BetType::Combo,
                'stake' => $stake,
                'odds' => $odds,
                'payout' => Bet::payoutFor($stake, $odds),
                'status' => BetStatus::Pending,
            ]);

            $bet->selections()->createMany($selections);

            return ['bet' => $bet->load('selections.fixture'), 'wallet' => $wallet];
        });
    }
}
