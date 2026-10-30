<?php

namespace App\Models;

use App\Enums\BetStatus;
use App\Enums\Side;
use Database\Factories\BetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'fixture_id', 'side', 'stake', 'odds', 'payout', 'status', 'settled_at'])]
class Bet extends Model
{
    /** @use HasFactory<BetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => Side::class,
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
     * @return BelongsTo<Fixture, $this>
     */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
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
