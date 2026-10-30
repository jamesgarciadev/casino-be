<?php

namespace App\Services\PandaScore;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Raw access to the PandaScore REST API. Returns PandaScore's payloads untouched;
 * EsportsCatalog caches and normalizes them. The token never leaves this server.
 */
class PandaScoreClient
{
    private const TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly ?string $token,
        private readonly string $baseUrl,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function videogames(): array
    {
        return $this->get('/videogames', ['per_page' => 100])->json();
    }

    /**
     * @param  'upcoming'|'running'|'past'  $window
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function matches(string $window, ?string $game, int $page, int $perPage): array
    {
        $query = ['page' => $page, 'per_page' => $perPage];

        if ($game !== null) {
            $query['filter[videogame]'] = $game;
        }

        $response = $this->get("/matches/{$window}", $query);

        return ['items' => $response->json(), 'total' => (int) $response->header('X-Total')];
    }

    /**
     * @return array<string, mixed>|null null when PandaScore has no match with that id
     */
    public function match(int $id): ?array
    {
        $response = $this->get("/matches/{$id}", allowNotFound: true);

        return $response->notFound() ? null : $response->json();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = [], bool $allowNotFound = false): Response
    {
        if (blank($this->token)) {
            throw $this->unavailable('PANDASCORE_API_TOKEN is not set');
        }

        try {
            $response = $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            throw $this->unavailable("{$path}: {$e->getMessage()}");
        }

        if ($response->successful() || ($allowNotFound && $response->notFound())) {
            return $response;
        }

        throw $this->unavailable("{$path}: HTTP {$response->status()}");
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            // Retry blips (timeouts, 5xx) only. 4xx means our request or token is wrong; 429 means wait.
            ->retry(2, 200, fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    private function unavailable(string $reason): PandaScoreUnavailable
    {
        Log::warning('PandaScore request failed', ['reason' => $reason]);

        return new PandaScoreUnavailable($reason);
    }
}
