<?php

namespace App\Models;

use App\Enums\OutcomeResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way a market can end, with its current odds and, once the market is settled, whether it won.
 */
#[Fillable(['market_id', 'key', 'odds', 'result'])]
class MarketOutcome extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'odds' => 'decimal:2',
            'result' => OutcomeResult::class,
        ];
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
