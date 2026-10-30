<?php

namespace App\Enums;

enum MatchSource: string
{
    /** Static demo fixtures from the design (DatabaseSeeder, local only). */
    case Seed = 'seed';

    /** Created and played out by casino-sim. */
    case Sim = 'sim';
}
