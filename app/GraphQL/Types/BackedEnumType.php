<?php

namespace App\GraphQL\Types;

use BackedEnum;
use GraphQL\Type\Definition\EnumType;
use Illuminate\Support\Str;

/**
 * Exposes a PHP backed enum as a GraphQL enum named by its upper-cased values (MatchState::Upcoming 'upcoming' ->
 * UPCOMING, MarketType::Map1x2 'map_1x2' -> MAP_1X2).
 * Resolvers receive and return the PHP enum cases directly, so models can keep their enum casts.
 */
final class BackedEnumType extends EnumType
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(string $enum, string $description)
    {
        $values = [];

        foreach ($enum::cases() as $case) {
            $values[Str::upper((string) $case->value)] = ['value' => $case];
        }

        parent::__construct(['name' => class_basename($enum), 'description' => $description, 'values' => $values]);
    }
}
