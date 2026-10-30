<?php

namespace App\GraphQL\Queries;

use App\Models\Bet;
use Illuminate\Database\Eloquent\Collection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class MyBets
{
    private const LIMIT = 100;

    /**
     * @return Collection<int, Bet>
     */
    public function __invoke(null $_, array $args, GraphQLContext $context): Collection
    {
        return $context->user()->bets()->with('selections.fixture')->latest()->latest('id')->limit(self::LIMIT)->get();
    }
}
