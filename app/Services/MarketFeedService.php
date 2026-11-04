<?php

namespace App\Services;

use App\Enums\MarketStatus;
use App\Enums\MarketType;
use App\Enums\OutcomeResult;
use App\Enums\Side;
use App\Exceptions\RuleViolation;
use App\Models\Fixture;
use App\Models\Market;
use Illuminate\Support\Collection;

/**
 * Applies casino-sim's market list to a match: opens new markets, moves odds, suspends, and settles. A market keeps what
 * it is about (type, map, half, round, line, side) and its outcomes for life, and once settled never changes again.
 * Markets the feed leaves out are left as they are. The winner market is be's own (see Fixture::syncWinnerMarket).
 *
 * Runs inside MatchFeedService's transaction, with the fixture row already locked.
 */
class MarketFeedService
{
    /**
     * @param  list<array{key: string, type: MarketType, map?: int|null, half?: int|null, round?: int|null, line?: float|null, side?: Side|null, status: MarketStatus, sort?: int|null, outcomes: list<array{key: string, odds: float, result?: OutcomeResult|null}>}>  $markets
     * @return Collection<int, Market> the markets this update settled
     */
    public function apply(Fixture $fixture, array $markets): Collection
    {
        $existing = $fixture->markets()->with('outcomes')->get()->keyBy('key');
        $settled = new Collection;
        $seen = [];

        foreach ($markets as $input) {
            $key = $input['key'];

            if (isset($seen[$key])) {
                throw new RuleViolation("Market {$key} is listed twice.");
            }

            $seen[$key] = true;
            $this->validate($fixture, $input);
            $market = $existing->get($key);

            if ($market !== null && ! $this->sameDefinition($market, $input)) {
                throw new RuleViolation("Market {$key} cannot change what it is about.");
            }

            if ($market?->status === MarketStatus::Settled) {
                // A repeat of the same result (a retried update) is fine; anything else would rewrite a settled bet.
                if ($input['status'] !== MarketStatus::Settled || $this->winningKey($input) !== $market->winningKey()) {
                    throw new RuleViolation("Market {$key} is already settled.");
                }

                continue;
            }

            $market = $this->save($fixture, $market, $input);

            if ($market->status === MarketStatus::Settled) {
                $settled->push($market);
            }
        }

        return $settled;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validate(Fixture $fixture, array $input): void
    {
        $key = $input['key'];

        if ($key === Market::WINNER_KEY || $input['type'] === MarketType::Winner) {
            throw new RuleViolation('The winner market follows oddsA, oddsB and winner.');
        }

        if (($input['map'] ?? 0) > $fixture->best_of) {
            throw new RuleViolation("Market {$key} is on map {$input['map']}, but the match is a best of {$fixture->best_of}.");
        }

        $keys = array_column($input['outcomes'], 'key');

        if (count($keys) < 2 || count(array_unique($keys)) !== count($keys)) {
            throw new RuleViolation("Market {$key} needs at least two outcomes with different keys.");
        }

        $results = array_map(fn (array $outcome) => $outcome['result'] ?? null, $input['outcomes']);

        if ($input['status'] === MarketStatus::Settled) {
            $won = count(array_filter($results, fn (?OutcomeResult $result) => $result === OutcomeResult::Won));

            if (in_array(null, $results, true) || $won !== 1) {
                throw new RuleViolation("Market {$key} must settle with exactly one won outcome and every other lost.");
            }
        } elseif (array_filter($results) !== []) {
            throw new RuleViolation("Market {$key} can only have results once it is settled.");
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function sameDefinition(Market $market, array $input): bool
    {
        $line = $input['line'] ?? null;

        return $market->type === $input['type']
            && $market->map === ($input['map'] ?? null)
            && $market->half === ($input['half'] ?? null)
            && $market->round === ($input['round'] ?? null)
            && $market->line === ($line === null ? null : number_format($line, 1, '.', ''))
            && $market->side === ($input['side'] ?? null)
            && $market->outcomes->pluck('key')->sort()->values()->all() === collect($input['outcomes'])->pluck('key')->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function winningKey(array $input): ?string
    {
        return collect($input['outcomes'])->firstWhere('result', OutcomeResult::Won)['key'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function save(Fixture $fixture, ?Market $market, array $input): Market
    {
        $market ??= $fixture->markets()->make([
            'key' => $input['key'],
            'type' => $input['type'],
            'map' => $input['map'] ?? null,
            'half' => $input['half'] ?? null,
            'round' => $input['round'] ?? null,
            'line' => $input['line'] ?? null,
            'side' => $input['side'] ?? null,
        ]);

        // Settling only sets results: outcomes keep the odds they were last offered at.
        $keepOdds = $market->exists && $input['status'] === MarketStatus::Settled;
        $current = $market->exists ? $market->outcomes->keyBy('key') : collect();
        // save() skips the query when nothing changed, which is most markets on most updates.
        $market->fill(['status' => $input['status'], 'sort' => $input['sort'] ?? 0])->save();

        foreach ($input['outcomes'] as $outcome) {
            $row = $current->get($outcome['key']) ?? $market->outcomes()->make(['key' => $outcome['key']]);
            $row->fill([
                ...($keepOdds ? [] : ['odds' => $outcome['odds']]),
                'result' => $outcome['result'] ?? null,
            ])->save();
        }

        return $market->unsetRelation('outcomes');
    }
}
