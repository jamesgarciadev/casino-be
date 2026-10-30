<?php

namespace App\GraphQL\Mutations;

use App\Enums\Side;
use App\Exceptions\RuleViolation;
use App\Models\Fixture;
use App\Services\BettingService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class PlaceBet
{
    public function __construct(private BettingService $betting) {}

    /**
     * @param  array{matchId: string, side: Side, stake: int}  $args
     */
    public function __invoke(null $_, array $args, GraphQLContext $context): array
    {
        $fixture = Fixture::find($args['matchId']) ?? throw new RuleViolation('Match not found.');

        return $this->betting->place($context->user(), $fixture, $args['side'], $args['stake']);
    }
}
