<?php

namespace App\Models;

use App\Enums\MatchSource;
use App\Enums\MatchState;
use App\Enums\Side;
use Database\Factories\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bettable match, exposed as `Match` in GraphQL (`match` is a reserved word in PHP).
 */
#[Fillable([
    'source', 'external_id', 'game', 'game_name', 'tournament', 'team_a', 'team_b', 'team_a_acronym',
    'team_b_acronym', 'score_a', 'score_b', 'odds_a', 'odds_b', 'starts_at', 'state', 'winner', 'stream_url',
])]
class Fixture extends Model
{
    /** @use HasFactory<FixtureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => MatchSource::class,
            'state' => MatchState::class,
            'winner' => Side::class,
            'odds_a' => 'decimal:2',
            'odds_b' => 'decimal:2',
            'starts_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Bet, $this>
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function oddsFor(Side $side): string
    {
        return $side === Side::A ? $this->odds_a : $this->odds_b;
    }

    /**
     * Live first, then by start time: the order the design's match lists use.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function liveFirst(Builder $query): void
    {
        $query->orderByRaw('case when state = ? then 0 else 1 end', [MatchState::Live->value])->orderBy('starts_at');
    }
}
