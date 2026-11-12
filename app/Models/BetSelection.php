<?php

namespace App\Models;

use App\Enums\BetStatus;
use App\Enums\Side;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * One pick of a bet: an outcome of one of a match's markets, at the odds it had when the bet was placed. Picks on the
 * winner market also keep their side.
 */
#[Fillable(['bet_id', 'fixture_id', 'market_outcome_id', 'side', 'odds', 'status'])]
class BetSelection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => Side::class,
            'status' => BetStatus::class,
            'odds' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Bet, $this>
     */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    /**
     * @return BelongsTo<Fixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /**
     * @return BelongsTo<MarketOutcome, $this>
     */
    public function outcome(): BelongsTo
    {
        return $this->belongsTo(MarketOutcome::class, 'market_outcome_id');
    }

    /**
     * @return HasOneThrough<Market, MarketOutcome, $this>
     */
    public function market(): HasOneThrough
    {
        return $this->hasOneThrough(Market::class, MarketOutcome::class, 'id', 'id', 'market_outcome_id', 'market_id');
    }
}
