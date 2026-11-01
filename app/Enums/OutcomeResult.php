<?php

namespace App\Enums;

/**
 * A settled market has exactly one won outcome; every other outcome is lost.
 */
enum OutcomeResult: string
{
    case Won = 'won';
    case Lost = 'lost';
}
