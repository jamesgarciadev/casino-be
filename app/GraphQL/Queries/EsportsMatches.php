<?php

namespace App\GraphQL\Queries;

use App\Services\PandaScore\EsportsCatalog;

final readonly class EsportsMatches
{
    public function __construct(private EsportsCatalog $catalog) {}

    /**
     * @param  array{window: 'upcoming'|'running'|'past', game?: string|null, page: int, perPage: int}  $args
     */
    public function __invoke(null $_, array $args): array
    {
        return $this->catalog->matches($args['window'], $args['game'] ?? null, $args['page'], $args['perPage']);
    }
}
