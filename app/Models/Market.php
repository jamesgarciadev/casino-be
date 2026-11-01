<?php

namespace App\Models;

use App\Enums\MarketStatus;
use App\Enums\MarketType;
use App\Enums\OutcomeResult;
use App\Enums\Side;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something to bet on in a match, e.g. "Map 3 - Total rounds, line 22.5". casino-sim prices and settles every market
 * except the winner, which be keeps in step with the fixture's own odds and winner.
 */
#[Fillable(['fixture_id', 'key', 'type', 'map', 'half', 'round', 'line', 'side', 'status', 'sort'])]
class Market extends Model
{
    public const WINNER_KEY = 'winner';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MarketType::class,
            'status' => MarketStatus::class,
            'side' => Side::class,
            'map' => 'integer',
            'half' => 'integer',
            'round' => 'integer',
            'line' => 'decimal:1',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Fixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /**
     * @return HasMany<MarketOutcome, $this>
     */
    public function outcomes(): HasMany
    {
        return $this->hasMany(MarketOutcome::class)->orderBy('id');
    }

    /** The outcome key that won, once settled. */
    public function winningKey(): ?string
    {
        return $this->outcomes->firstWhere('result', OutcomeResult::Won)?->key;
    }
}
