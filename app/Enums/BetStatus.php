<?php

namespace App\Enums;

enum BetStatus: string
{
    case Pending = 'pending';
    case Won = 'won';
    case Lost = 'lost';
}
