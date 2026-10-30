<?php

namespace App\GraphQL\Mutations;

use App\Models\Fixture;
use App\Services\MatchFeedService;

final readonly class SimUpdateMatch
{
    public function __construct(private MatchFeedService $feed) {}

    /**
     * @param  array<string, mixed>  $args  `id` plus the snake_case changes (renamed in the schema)
     */
    public function __invoke(null $_, array $args): Fixture
    {
        $changes = array_filter(array_diff_key($args, ['id' => true]), fn ($value) => $value !== null);

        return $this->feed->update(Fixture::findOrFail($args['id']), $changes);
    }
}
