<?php

namespace App\Enums;

/**
 * Bets are taken while a market is open. Suspended markets keep their odds on show but take no bets; settled markets
 * have their result and never change again.
 */
enum MarketStatus: string
{
    case Open = 'open';
    case Suspended = 'suspended';
    case Settled = 'settled';
}
