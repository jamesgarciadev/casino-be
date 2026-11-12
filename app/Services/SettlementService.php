<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\MarketStatus;
use App\Enums\MatchState;
use App\Enums\OutcomeResult;
use App\Models\Bet;
use App\Models\BetSelection;
use App\Models\Fixture;
use App\Models\Market;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class SettlementService
{
    /**
     * Settle every pick on a finished match's settled markets (the winner market included).
     *
     * @return int number of bets settled
     */
    public function settle(Fixture $fixture): int
    {
        if ($fixture->state !== MatchState::Finished || $fixture->winner === null) {
            throw new LogicException("Fixture {$fixture->id} has no result to settle.");
        }

        return $this->settleMarkets($fixture->markets()->where('status', MarketStatus::Settled)->get());
    }

    /**
     * Mark every pending pick on these settled markets won or lost from its outcome's result, then settle the bets
     * that are now decided: a bet is lost as soon as one pick loses, and won (and paid) once every pick has won. A combo
     * with picks still to play stays pending. Only pending rows are touched, so running it twice is harmless.
     *
     * @param  Collection<int, Market>  $markets
     * @return int number of bets settled
     */
    public function settleMarkets(Collection $markets): int
    {
        if ($markets->contains(fn (Market $market) => $market->status !== MarketStatus::Settled)) {
            throw new LogicException('Only settled markets can be settled.');
        }

        if ($markets->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($markets) {
            $selections = BetSelection::query()
                ->whereHas('outcome', fn ($query) => $query->whereIn('market_id', $markets->pluck('id')))
                ->where('status', BetStatus::Pending)
                ->with('outcome')
                ->lockForUpdate()
                ->get();

            foreach ($selections as $selection) {
                $selection->update([
                    'status' => $selection->outcome->result === OutcomeResult::Won ? BetStatus::Won : BetStatus::Lost,
                ]);
            }

            $bets = Bet::whereKey($selections->pluck('bet_id')->unique())
                ->where('status', BetStatus::Pending)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('selections')
                ->get();

            $settled = 0;

            foreach ($bets as $bet) {
                $statuses = $bet->selections->pluck('status');

                if ($statuses->contains(BetStatus::Lost)) {
                    $bet->update(['status' => BetStatus::Lost, 'settled_at' => now()]);
                } elseif ($statuses->every(fn (BetStatus $status) => $status === BetStatus::Won)) {
                    $bet->update(['status' => BetStatus::Won, 'settled_at' => now()]);
                    Wallet::whereKey($bet->user_id)->increment('balance', $bet->payout);
                } else {
                    continue;
                }

                $settled++;
            }

            return $settled;
        });
    }
}
