<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\MatchState;
use App\Models\Fixture;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use LogicException;

class SettlementService
{
    /**
     * Pay out the winning side of a finished match. Only pending bets are touched, so running it twice is harmless.
     *
     * @return int number of bets settled
     */
    public function settle(Fixture $fixture): int
    {
        if ($fixture->state !== MatchState::Finished || $fixture->winner === null) {
            throw new LogicException("Fixture {$fixture->id} has no result to settle.");
        }

        return DB::transaction(function () use ($fixture) {
            $bets = $fixture->bets()->where('status', BetStatus::Pending)->lockForUpdate()->get();

            foreach ($bets as $bet) {
                $won = $bet->side === $fixture->winner;

                $bet->update(['status' => $won ? BetStatus::Won : BetStatus::Lost, 'settled_at' => now()]);

                if ($won) {
                    Wallet::whereKey($bet->user_id)->increment('balance', $bet->payout);
                }
            }

            return $bets->count();
        });
    }
}
