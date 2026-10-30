<?php

namespace App\GraphQL\Queries;

use App\Enums\MatchState;
use App\Models\Fixture;
use Illuminate\Database\Eloquent\Collection;

final readonly class Matches
{
    private const LIMIT = 100;

    /**
     * @param  array{state?: MatchState|null, game?: string|null}  $args
     * @return Collection<int, Fixture>
     */
    public function __invoke(null $_, array $args): Collection
    {
        $state = $args['state'] ?? null;
        $query = Fixture::query()->when($args['game'] ?? null, fn ($q, string $game) => $q->where('game', $game));

        if ($state === MatchState::Finished) {
            return $query->where('state', $state)->orderByDesc('starts_at')->limit(self::LIMIT)->get();
        }

        return $query
            ->when($state, fn ($q) => $q->where('state', $state), fn ($q) => $q->where('state', '!=', MatchState::Finished))
            ->liveFirst()
            ->limit(self::LIMIT)
            ->get();
    }
}
