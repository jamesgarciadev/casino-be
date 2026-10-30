<?php

namespace App\Enums;

enum BetStatus: string
{
    case Pending = 'pending';
    case Won = 'won';
    case Lost = 'lost';
    /** A single the player withdrew before its match finished, for its cash-out value. */
    case CashedOut = 'cashed_out';
}
