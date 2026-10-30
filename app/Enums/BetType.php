<?php

namespace App\Enums;

/**
 * A single backs one match; a combo (parlay) backs several different matches and wins only if every pick wins.
 */
enum BetType: string
{
    case Single = 'single';
    case Combo = 'combo';
}
