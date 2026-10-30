<?php

namespace App\GraphQL\Queries;

use App\Services\PandaScore\EsportsCatalog;

final readonly class Videogames
{
    public function __construct(private EsportsCatalog $catalog) {}

    public function __invoke(): array
    {
        return $this->catalog->videogames();
    }
}
