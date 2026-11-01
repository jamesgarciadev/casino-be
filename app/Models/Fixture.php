<?php

namespace App\Models;

use App\Enums\MarketStatus;
use App\Enums\MarketType;
use App\Enums\MatchSource;
use App\Enums\MatchState;
use App\Enums\OutcomeResult;
use App\Enums\Side;
use Database\Factories\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A bettable match, exposed as `Match` in GraphQL (`match` is a reserved word in PHP).
 */
#[Fillable([
    'source', 'external_id', 'game', 'game_name', 'tournament', 'team_a', 'team_b', 'team_a_acronym',
    'team_b_acronym', 'best_of', 'score_a', 'score_b', 'maps', 'odds_a', 'odds_b', 'starts_at', 'state', 'winner',
    'stream_url',
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
            'best_of' => 'integer',
            'maps' => 'array',
            'odds_a' => 'decimal:2',
            'odds_b' => 'decimal:2',
            'starts_at' => 'datetime',
        ];
    }

    /**
     * The winner market mirrors `odds_a`, `odds_b` and `winner`, so it is created with the fixture and follows every
     * change to them. Every pick, including a pick by side, then settles the same way: through its market.
     */
    protected static function booted(): void
    {
        static::created(fn (self $fixture) => $fixture->syncWinnerMarket());
        static::updated(function (self $fixture) {
            if ($fixture->wasChanged(['odds_a', 'odds_b', 'state', 'winner'])) {
                $fixture->syncWinnerMarket();
            }
        });
    }

    /**
     * @return HasMany<Market, $this>
     */
    public function markets(): HasMany
    {
        return $this->hasMany(Market::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasOne<Market, $this>
     */
    public function winnerMarket(): HasOne
    {
        return $this->hasOne(Market::class)->where('key', Market::WINNER_KEY);
    }

    /**
     * @return HasMany<BetSelection, $this>
     */
    public function betSelections(): HasMany
    {
        return $this->hasMany(BetSelection::class);
    }

    public function oddsFor(Side $side): string
    {
        return $side === Side::A ? $this->odds_a : $this->odds_b;
    }

    /**
     * Maps played so far, numbered from 1; the last one is in play while the match is live.
     *
     * @return list<array{number: int, name: string, scoreA: int, scoreB: int, winner: Side|null}>
     */
    public function mapScores(): array
    {
        return array_map(fn (array $map, int $index) => [
            'number' => $index + 1,
            'name' => $map['name'],
            'scoreA' => $map['scoreA'],
            'scoreB' => $map['scoreB'],
            'winner' => Side::tryFrom((string) ($map['winner'] ?? '')),
        ], $this->maps ?? [], array_keys($this->maps ?? []));
    }

    /** Copy the fixture's odds and, once it has finished, its winner onto the winner market. */
    public function syncWinnerMarket(): Market
    {
        $market = $this->winnerMarket()->firstOrCreate(
            ['key' => Market::WINNER_KEY],
            ['type' => MarketType::Winner, 'status' => MarketStatus::Open, 'sort' => 0],
        );

        if ($market->status === MarketStatus::Settled) {
            return $market;
        }

        $finished = $this->state === MatchState::Finished && $this->winner !== null;

        foreach (Side::cases() as $side) {
            $market->outcomes()->updateOrCreate(['key' => $side->value], [
                'odds' => $this->oddsFor($side),
                'result' => $finished ? ($side === $this->winner ? OutcomeResult::Won : OutcomeResult::Lost) : null,
            ]);
        }

        if ($finished) {
            $market->update(['status' => MarketStatus::Settled]);
        }

        return $market->unsetRelation('outcomes');
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
