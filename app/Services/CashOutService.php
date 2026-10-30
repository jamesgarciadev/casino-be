<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\MatchState;
use App\Exceptions\RuleViolation;
use App\Models\Bet;
use App\Models\Fixture;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class CashOutService
{
    public function __construct(private WalletService $wallets) {}

    /**
     * Withdraw an open single for its value at the match's current odds. `$expected` is the value the player was
     * shown: a higher current value is paid, a lower one is refused so a moving price never pays less than shown.
     *
     * Locks the fixture, then the bet, then the wallet: the same order as a feed update that finishes the match and
     * settles its bets, so a cash-out and a settlement can't both pay.
     *
     * @return array{bet: Bet, wallet: Wallet}
     */
    public function cashOut(User $user, int $betId, int $expected): array
    {
        $bet = $user->bets()->with('selections')->find($betId) ?? throw new RuleViolation('Bet not found.');

        if ($bet->type !== BetType::Single) {
            throw new RuleViolation('Only singles can be cashed out.');
        }

        $this->wallets->for($user);

        return DB::transaction(function () use ($user, $bet, $expected) {
            $selection = $bet->selections->sole();
            $fixture = Fixture::lockForUpdate()->findOrFail($selection->fixture_id);
            $bet = Bet::lockForUpdate()->findOrFail($bet->id);

            if ($bet->status !== BetStatus::Pending) {
                throw new RuleViolation('This bet is already settled.');
            }

            if ($fixture->state === MatchState::Finished) {
                throw new RuleViolation('Betting is closed for this match.');
            }

            $value = Bet::cashOutValue($bet->stake, $bet->odds, $fixture->oddsFor($selection->side));

            if ($value < $expected) {
                throw new RuleViolation('Cash-out value changed to '.number_format($value).' CR.');
            }

            $bet->update(['status' => BetStatus::CashedOut, 'cash_out' => $value, 'settled_at' => now()]);
            // Settlement only looks at pending selections, so the match finishing later leaves this bet alone.
            $selection->update(['status' => BetStatus::CashedOut]);

            $wallet = Wallet::lockForUpdate()->findOrFail($user->id);
            $wallet->increment('balance', $value);

            return ['bet' => $bet->load('selections.fixture'), 'wallet' => $wallet];
        });
    }
}
