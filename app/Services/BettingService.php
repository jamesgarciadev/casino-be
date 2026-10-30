<?php

namespace App\Services;

use App\Enums\BetStatus;
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
     * Debit the stake and record the bet in one transaction. Odds are read from the fixture row,
     * never from the client, and the wallet row is locked so two bets can't spend the same credits.
     *
     * @return array{bet: Bet, wallet: Wallet}
     */
    public function place(User $user, Fixture $fixture, Side $side, int $stake): array
    {
        if ($stake < 1 || $stake > self::MAX_STAKE) {
            throw new RuleViolation('Stake must be between 1 and '.number_format(self::MAX_STAKE).' credits.');
        }

        $this->wallets->for($user);

        return DB::transaction(function () use ($user, $fixture, $side, $stake) {
            $fixture = Fixture::lockForUpdate()->findOrFail($fixture->id);

            if ($fixture->state === MatchState::Finished) {
                throw new RuleViolation('Betting is closed for this match.');
            }

            $wallet = Wallet::lockForUpdate()->findOrFail($user->id);

            if ($wallet->balance < $stake) {
                throw new RuleViolation('Insufficient balance.');
            }

            $wallet->decrement('balance', $stake);
            $odds = $fixture->oddsFor($side);

            $bet = $user->bets()->create([
                'fixture_id' => $fixture->id,
                'side' => $side,
                'stake' => $stake,
                'odds' => $odds,
                'payout' => Bet::payoutFor($stake, $odds),
                'status' => BetStatus::Pending,
            ]);

            return ['bet' => $bet->setRelation('fixture', $fixture), 'wallet' => $wallet];
        });
    }
}
