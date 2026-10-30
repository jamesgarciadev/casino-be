<?php

namespace App\Enums;

/**
 * Which team a bet backs, or which team won.
 */
enum Side: string
{
    case A = 'a';
    case B = 'b';
}
