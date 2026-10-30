<?php

namespace App\Services\PandaScore;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cached, normalized esports data for the GraphQL API.
 *
 * Every read goes through the cache, so client traffic never reaches PandaScore directly
 * (the plan allows ~1000 requests/hour). A day-long stale copy is served when PandaScore fails.
 * Cached values are plain arrays: the cache store refuses to unserialize objects.
 */
class EsportsCatalog
{
    /** @var array<string, int> seconds, per match window */
    private const MATCHES_TTL = ['running' => 15, 'upcoming' => 60, 'past' => 600];

    private const MATCH_TTL = 30;

    private const VIDEOGAMES_TTL = 86400;

    private const STALE_TTL = 86400;

    public function __construct(private readonly PandaScoreClient $client) {}

    /**
     * @return list<array{id: string, name: string, slug: string}>
     */
    public function videogames(): array
    {
        return $this->remember('videogames', self::VIDEOGAMES_TTL, fn () => array_map(
            $this->videogame(...),
            $this->client->videogames(),
        ));
    }

    public function isKnownGame(string $slug): bool
    {
        return in_array($slug, array_column($this->videogames(), 'slug'), true);
    }

    /**
     * @param  'upcoming'|'running'|'past'  $window
     * @return array{data: list<array<string, mixed>>, pageInfo: array{currentPage: int, perPage: int, total: int, hasMorePages: bool}}
     */
    public function matches(string $window, ?string $game, int $page, int $perPage): array
    {
        $empty = ['items' => [], 'total' => 0];

        // An unknown slug can only ever return nothing, so don't spend a request finding out.
        $result = $game !== null && ! $this->isKnownGame($game)
            ? $empty
            : $this->remember(
                "matches:{$window}:".($game ?? 'all').":{$page}:{$perPage}",
                self::MATCHES_TTL[$window],
                function () use ($window, $game, $page, $perPage) {
                    $result = $this->client->matches($window, $game, $page, $perPage);

                    return ['items' => array_map($this->match(...), $result['items']), 'total' => $result['total']];
                },
            );

        return [
            'data' => $result['items'],
            'pageInfo' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $result['total'],
                'hasMorePages' => $page * $perPage < $result['total'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        // Not-found is cached as false so repeated lookups of a bad id stay local.
        $match = $this->remember("match:{$id}", self::MATCH_TTL, function () use ($id) {
            $match = $this->client->match($id);

            return $match === null ? false : $this->match($match);
        });

        return $match ?: null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fetch
     * @return T
     */
    private function remember(string $key, int $ttl, Closure $fetch): mixed
    {
        $key = "pandascore:{$key}";
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $value = $fetch();
        } catch (PandaScoreUnavailable $e) {
            return Cache::get("{$key}:stale") ?? throw $e;
        }

        Cache::put($key, $value, $ttl);
        Cache::put("{$key}:stale", $value, self::STALE_TTL);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $game
     * @return array{id: string, name: string, slug: string}
     */
    private function videogame(array $game): array
    {
        return ['id' => (string) $game['id'], 'name' => $game['name'], 'slug' => $game['slug']];
    }

    /**
     * Keep only what the API exposes, so PandaScore can add or rename fields without changing our schema.
     *
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function match(array $match): array
    {
        return [
            'id' => (string) $match['id'],
            'name' => $match['name'],
            'status' => $match['status'],
            'matchType' => $match['match_type'] ?? null,
            'numberOfGames' => $match['number_of_games'] ?? null,
            'scheduledAt' => $this->date($match['scheduled_at'] ?? null),
            'beginAt' => $this->date($match['begin_at'] ?? null),
            'endAt' => $this->date($match['end_at'] ?? null),
            'game' => $this->videogame($match['videogame']),
            'league' => isset($match['league']) ? [
                'id' => (string) $match['league']['id'],
                'name' => $match['league']['name'],
                'imageUrl' => $match['league']['image_url'] ?? null,
            ] : null,
            'tournament' => Arr::get($match, 'tournament.name'),
            'serie' => Arr::get($match, 'serie.full_name'),
            'opponents' => array_map(fn (array $entry) => [
                'id' => (string) $entry['opponent']['id'],
                'type' => strtoupper($entry['type']),
                'name' => $entry['opponent']['name'],
                'acronym' => $entry['opponent']['acronym'] ?? null,
                'imageUrl' => $entry['opponent']['image_url'] ?? null,
            ], $match['opponents'] ?? []),
            'scores' => array_map(fn (array $result) => [
                'opponentId' => (string) ($result['team_id'] ?? $result['player_id']),
                'score' => $result['score'],
            ], $match['results'] ?? []),
            'winnerId' => isset($match['winner_id']) ? (string) $match['winner_id'] : null,
            'streams' => array_map(fn (array $stream) => [
                'url' => $stream['raw_url'],
                'embedUrl' => $stream['embed_url'] ?? null,
                'language' => $stream['language'] ?? null,
                'main' => (bool) ($stream['main'] ?? false),
                'official' => (bool) ($stream['official'] ?? false),
            ], $match['streams_list'] ?? []),
        ];
    }

    private function date(?string $value): ?string
    {
        // DateTimeTz expects `+00:00`, PandaScore sends `Z`.
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
