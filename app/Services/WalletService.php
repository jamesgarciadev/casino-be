<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;

/**
 * Play-money wallets. Every new account starts with STARTING_BALANCE credits.
 */
class WalletService
{
    public const STARTING_BALANCE = 1000;

    public const TOP_UP_AMOUNT = 500;

    /**
     * The user's wallet, opened with the starting balance on first use (accounts created before wallets existed).
     */
    public function for(User $user): Wallet
    {
        return Wallet::firstOrCreate(['user_id' => $user->id], ['balance' => self::STARTING_BALANCE]);
    }

    public function topUp(User $user): Wallet
    {
        $wallet = $this->for($user);
        $wallet->increment('balance', self::TOP_UP_AMOUNT);

        return $wallet;
    }
}
