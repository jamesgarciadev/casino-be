<?php

namespace App\GraphQL\Queries;

use App\Enums\BetStatus;
use App\Models\Bet;
use Illuminate\Database\Eloquent\Collection;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class MyBets
{
    /**
     * Newest first by id, so `before` (the last id a client holds) pages without gaps or repeats as bets are placed
     * or settled in the meantime.
     *
     * @param  array{open?: bool|null, first: int, before?: string|null}  $args
     * @return Collection<int, Bet>
     */
    public function __invoke(null $_, array $args, GraphQLContext $context): Collection
    {
        $open = $args['open'] ?? null;
        $before = $args['before'] ?? null;

        return $context->user()->bets()
            ->when($open !== null, fn ($query) => $open
                ? $query->where('status', BetStatus::Pending)
                : $query->where('status', '!=', BetStatus::Pending))
            ->when($before !== null, fn ($query) => $query->where('id', '<', (int) $before))
            ->with('selections.fixture')
            ->orderByDesc('id')
            ->limit($args['first'])
            ->get();
    }
}
