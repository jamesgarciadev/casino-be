<?php

namespace App\Enums;

/**
 * Lifecycle of a bettable match. It only ever moves forward.
 */
enum MatchState: string
{
    case Upcoming = 'upcoming';
    case Live = 'live';
    case Finished = 'finished';

    public function canMoveTo(self $next): bool
    {
        return array_search($next, self::cases(), true) >= array_search($this, self::cases(), true);
    }
}
