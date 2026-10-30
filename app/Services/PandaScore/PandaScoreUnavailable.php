<?php

namespace App\Services\PandaScore;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * PandaScore could not be reached or returned an error. The upstream detail is logged;
 * clients only ever see the generic message.
 */
final class PandaScoreUnavailable extends RuntimeException implements ClientAware
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Esports data is temporarily unavailable. Try again shortly.');
    }

    public function isClientSafe(): bool
    {
        return true;
    }
}
