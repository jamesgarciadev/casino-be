<?php

namespace App\Models;

use App\Enums\BetStatus;
use App\Enums\Side;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pick of a bet: a side of a match at the odds it had when the bet was placed.
 */
#[Fillable(['bet_id', 'fixture_id', 'side', 'odds', 'status'])]
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
}
