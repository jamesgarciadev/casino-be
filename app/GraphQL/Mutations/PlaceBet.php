<?php

namespace App\GraphQL\Mutations;

use App\Enums\Side;
use App\Services\BettingService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class PlaceBet
{
    public function __construct(private BettingService $betting) {}

    /**
     * @param  array{matchId: string, side?: Side|null, outcomeId?: string|null, stake: int}  $args
     */
    public function __invoke(null $_, array $args, GraphQLContext $context): array
    {
        return $this->betting->placeSingle($context->user(), $args, $args['stake']);
    }
}
