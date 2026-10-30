<?php

namespace App\Models;

use App\Enums\BetStatus;
use App\Enums\BetType;
use Database\Factories\BetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stake on one selection (single) or on several selections from different matches (combo).
 * `odds` is the total: the product of the selections' odds.
 */
#[Fillable(['user_id', 'type', 'stake', 'odds', 'payout', 'status', 'settled_at'])]
class Bet extends Model
{
    /** Keeps stake x odds well inside 64-bit integers however many picks a combo has. */
    public const MAX_TOTAL_ODDS = 1_000_000;

    /** @use HasFactory<BetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BetType::class,
            'status' => BetStatus::class,
            'stake' => 'integer',
            'payout' => 'integer',
            'odds' => 'decimal:2',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BetSelection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(BetSelection::class)->orderBy('id');
    }

    /**
     * The selections' odds multiplied in whole cents, rounding half up after each pick, capped at MAX_TOTAL_ODDS.
     * casino-web mirrors this so the slip shows the total be will use.
     *
     * @param  list<string>  $odds
     */
    public static function totalOdds(array $odds): string
    {
        $cents = 100;

        foreach ($odds as $pick) {
            $cents = min(intdiv($cents * (int) round(((float) $pick) * 100) + 50, 100), self::MAX_TOTAL_ODDS * 100);
        }

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * stake x odds rounded half up to whole credits, computed in integers so decimals never drift.
     */
    public static function payoutFor(int $stake, string $odds): int
    {
        $cents = (int) round(((float) $odds) * 100);

        return intdiv($stake * $cents + 50, 100);
    }
}
