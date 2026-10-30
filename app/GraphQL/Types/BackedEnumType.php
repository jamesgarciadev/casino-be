<?php

namespace App\GraphQL\Types;

use BackedEnum;
use GraphQL\Type\Definition\EnumType;
use Illuminate\Support\Str;

/**
 * Exposes a PHP backed enum as a GraphQL enum with SCREAMING_CASE names (MatchState::Upcoming -> UPCOMING).
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
            $values[Str::upper(Str::snake($case->name))] = ['value' => $case];
        }

        parent::__construct(['name' => class_basename($enum), 'description' => $description, 'values' => $values]);
    }
}
