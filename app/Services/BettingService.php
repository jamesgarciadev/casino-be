<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\MarketStatus;
use App\Enums\MarketType;
use App\Enums\MatchState;
use App\Enums\Side;
use App\Exceptions\RuleViolation;
use App\Models\Bet;
use App\Models\Fixture;
use App\Models\MarketOutcome;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class BettingService
{
    public const MAX_STAKE = 1_000_000;

    public function __construct(private WalletService $wallets) {}

    /**
     * A single on one side of a match, i.e. its winner market.
     *
     * @return array{bet: Bet, wallet: Wallet}
     */
    public function place(User $user, Fixture $fixture, Side $side, int $stake): array
    {
        return $this->placeSelections($user, [['matchId' => $fixture->id, 'side' => $side]], $stake);
    }

    /**
     * A single on any market: the pick names a side (the winner market) or an outcome id.
     *
     * @param  array{matchId: int|string, side?: Side|null, outcomeId?: int|string|null}  $pick
     * @return array{bet: Bet, wallet: Wallet}
     */
    public function placeSingle(User $user, array $pick, int $stake): array
    {
        return $this->placeSelections($user, [$pick], $stake);
    }

    /**
     * A combo: two or more picks, each from a different match. Wins only if every pick wins.
     *
     * @param  list<array{matchId: int|string, side?: Side|null, outcomeId?: int|string|null}>  $picks
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
     * Debit the stake and record the bet in one transaction. Odds are read from the outcome rows, never from the
     * client, after the fixtures are locked (the feed locks them too before it changes odds or settles), and the wallet
     * row is locked so two bets can't spend the same credits.
     *
     * @param  list<array{matchId: int|string, side?: Side|null, outcomeId?: int|string|null}>  $picks
     * @return array{bet: Bet, wallet: Wallet}
     */
    private function placeSelections(User $user, array $picks, int $stake): array
    {
        if ($stake < 1 || $stake > self::MAX_STAKE) {
            throw new RuleViolation('Stake must be between 1 and '.number_format(self::MAX_STAKE).' credits.');
        }

        $byMatch = [];
        foreach ($picks as $pick) {
            $id = (int) $pick['matchId'];

            if (isset($byMatch[$id])) {
                throw new RuleViolation('A combo can only include one pick per match.');
            }

            if ((($pick['side'] ?? null) === null) === (($pick['outcomeId'] ?? null) === null)) {
                throw new RuleViolation('Pick either a side or an outcome.');
            }

            $byMatch[$id] = $pick;
        }

        $this->wallets->for($user);

        return DB::transaction(function () use ($user, $byMatch, $stake) {
            // Locked in id order so two combos sharing matches can't deadlock.
            $fixtures = Fixture::whereKey(array_keys($byMatch))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($fixtures->count() !== count($byMatch)) {
                throw new RuleViolation('Match not found.');
            }

            foreach ($fixtures as $fixture) {
                if ($fixture->state === MatchState::Finished) {
                    throw new RuleViolation(count($byMatch) === 1
                        ? 'Betting is closed for this match.'
                        : "Betting is closed for {$fixture->team_a} vs {$fixture->team_b}.");
                }
            }

            $outcomes = array_map(fn (array $pick) => $this->outcomeFor($fixtures[(int) $pick['matchId']], $pick), $byMatch);

            $wallet = Wallet::lockForUpdate()->findOrFail($user->id);

            if ($wallet->balance < $stake) {
                throw new RuleViolation('Insufficient balance.');
            }

            $wallet->decrement('balance', $stake);

            // Keep the order the picks were sent in.
            $selections = array_map(fn (int $id) => [
                'fixture_id' => $id,
                'market_outcome_id' => $outcomes[$id]->id,
                'side' => $outcomes[$id]->market->type === MarketType::Winner ? Side::from($outcomes[$id]->key) : null,
                'odds' => $outcomes[$id]->odds,
                'status' => BetStatus::Pending,
            ], array_keys($byMatch));

            $odds = Bet::totalOdds(array_column($selections, 'odds'));

            $bet = $user->bets()->create([
                'type' => count($selections) === 1 ? BetType::Single : BetType::Combo,
                'stake' => $stake,
                'odds' => $odds,
                'payout' => Bet::payoutFor($stake, $odds),
                'status' => BetStatus::Pending,
            ]);

            $bet->selections()->createMany($selections);

            return ['bet' => $bet->load(['selections.fixture', 'selections.market', 'selections.outcome']), 'wallet' => $wallet];
        });
    }

    /**
     * The outcome a pick backs, checked to belong to the match and to a market that is taking bets.
     *
     * @param  array{side?: Side|null, outcomeId?: int|string|null}  $pick
     */
    private function outcomeFor(Fixture $fixture, array $pick): MarketOutcome
    {
        $outcome = isset($pick['side'])
            ? MarketOutcome::where('key', $pick['side']->value)
                ->whereHas('market', fn ($query) => $query->where('fixture_id', $fixture->id)->where('type', MarketType::Winner))
                ->first()
            : MarketOutcome::whereKey((int) $pick['outcomeId'])
                ->whereHas('market', fn ($query) => $query->where('fixture_id', $fixture->id))
                ->first();

        if ($outcome === null) {
            throw new RuleViolation('Outcome not found.');
        }

        return match ($outcome->market->status) {
            MarketStatus::Open => $outcome,
            MarketStatus::Suspended => throw new RuleViolation('This market is suspended.'),
            MarketStatus::Settled => throw new RuleViolation('This market is closed.'),
        };
    }
}
