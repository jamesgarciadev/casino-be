<?php

namespace App\Services;

use App\Enums\MatchSource;
use App\Enums\MatchState;
use App\Enums\Side;
use App\Exceptions\RuleViolation;
use App\Models\Fixture;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Execution\Utils\Subscription;

/**
 * Writes from casino-sim. The simulator decides scores and odds; be enforces the lifecycle, settles bets
 * and pushes every change to `matchUpdated` subscribers (via Reverb; failures are logged, never thrown).
 */
class MatchFeedService
{
    public function __construct(private SettlementService $settlement) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Fixture
    {
        $state = $attributes['state'] ?? MatchState::Upcoming;

        if ($state === MatchState::Finished) {
            throw new RuleViolation('A new match cannot start as finished.');
        }

        return $this->broadcast(Fixture::create([...$attributes, 'source' => MatchSource::Sim, 'state' => $state])->refresh());
    }

    /**
     * @param  array{score_a?: int, score_b?: int, odds_a?: float, odds_b?: float, state?: MatchState, winner?: Side|null}  $changes
     */
    public function update(Fixture $fixture, array $changes): Fixture
    {
        $fixture = DB::transaction(function () use ($fixture, $changes) {
            $fixture = Fixture::lockForUpdate()->findOrFail($fixture->id);

            if ($fixture->state === MatchState::Finished) {
                throw new RuleViolation('Match is already finished.');
            }

            $next = $changes['state'] ?? $fixture->state;

            if (! $fixture->state->canMoveTo($next)) {
                throw new RuleViolation("Match cannot move from {$fixture->state->value} to {$next->value}.");
            }

            $winner = $changes['winner'] ?? null;

            if (($next === MatchState::Finished) !== ($winner !== null)) {
                throw new RuleViolation('A winner is required when, and only when, a match finishes.');
            }

            $fixture->update($changes);

            if ($next === MatchState::Finished) {
                $this->settlement->settle($fixture);
            }

            return $fixture;
        });

        return $this->broadcast($fixture);
    }

    /** Broadcast after the transaction has committed, so subscribers never see a rolled-back state. */
    private function broadcast(Fixture $fixture): Fixture
    {
        Subscription::broadcast('matchUpdated', $fixture);

        return $fixture;
    }
}
