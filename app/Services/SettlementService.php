<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\MatchState;
use App\Models\Bet;
use App\Models\Fixture;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use LogicException;

class SettlementService
{
    /**
     * Mark every pick on a finished match won or lost, then settle the bets that are now decided: a bet is lost as
     * soon as one pick loses, and won (and paid) once every pick has won. A combo with picks still to play stays
     * pending. Only pending rows are touched, so running it twice is harmless.
     *
     * @return int number of bets settled
     */
    public function settle(Fixture $fixture): int
    {
        if ($fixture->state !== MatchState::Finished || $fixture->winner === null) {
            throw new LogicException("Fixture {$fixture->id} has no result to settle.");
        }

        return DB::transaction(function () use ($fixture) {
            $selections = $fixture->betSelections()->where('status', BetStatus::Pending)->lockForUpdate()->get();

            foreach ($selections as $selection) {
                $selection->update(['status' => $selection->side === $fixture->winner ? BetStatus::Won : BetStatus::Lost]);
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
