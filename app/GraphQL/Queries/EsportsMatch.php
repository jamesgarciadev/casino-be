<?php

namespace App\GraphQL\Queries;

use App\Services\PandaScore\EsportsCatalog;

final readonly class EsportsMatch
{
    public function __construct(private EsportsCatalog $catalog) {}

    /**
     * @param  array{id: string}  $args
     */
    public function __invoke(null $_, array $args): ?array
    {
        return $this->catalog->find((int) $args['id']);
    }
}
