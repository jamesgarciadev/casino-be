<?php

namespace App\Exceptions;

use GraphQL\Error\ClientAware;
use RuntimeException;

/**
 * A business rule rejected the request (e.g. insufficient balance). The message is shown to the client as-is.
 */
final class RuleViolation extends RuntimeException implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }
}
