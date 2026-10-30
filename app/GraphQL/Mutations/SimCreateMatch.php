<?php

namespace App\GraphQL\Mutations;

use App\Models\Fixture;
use App\Services\MatchFeedService;

final readonly class SimCreateMatch
{
    public function __construct(private MatchFeedService $feed) {}

    /**
     * @param  array<string, mixed>  $args  snake_case attributes (renamed in the schema)
     */
    public function __invoke(null $_, array $args): Fixture
    {
        return $this->feed->create(array_filter($args, fn ($value) => $value !== null));
    }
}
