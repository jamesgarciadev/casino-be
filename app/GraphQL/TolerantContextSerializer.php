<?php

namespace App\GraphQL;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nuwave\Lighthouse\Execution\ContextSerializer;

/**
 * Stored subscriptions remember who subscribed and reload that user on every broadcast. If the user has since been
 * deleted, Lighthouse's serializer throws, and the whole broadcast (to every subscriber) is dropped. Restore a
 * missing user as a guest instead; matchUpdated is public, so nothing depends on it.
 */
final class TolerantContextSerializer extends ContextSerializer
{
    protected function getRestoredPropertyValue($value): mixed
    {
        try {
            return parent::getRestoredPropertyValue($value);
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
