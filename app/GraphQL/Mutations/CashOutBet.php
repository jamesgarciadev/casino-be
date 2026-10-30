<?php

namespace App\GraphQL\Mutations;

use App\Services\CashOutService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class CashOutBet
{
    public function __construct(private CashOutService $cashOuts) {}

    /**
     * @param  array{betId: string, expected: int}  $args
     */
    public function __invoke(null $_, array $args, GraphQLContext $context): array
    {
        return $this->cashOuts->cashOut($context->user(), (int) $args['betId'], $args['expected']);
    }
}
