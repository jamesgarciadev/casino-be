<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Offline PandaScore: any request without a matching fake fails the test, so the
 * default suite never touches the network or the real rate limit.
 */
trait FakesPandaScore
{
    protected const PANDASCORE = 'https://api.pandascore.test';

    protected function setUpFakesPandaScore(): void
    {
        config(['services.pandascore.token' => 'test-token', 'services.pandascore.base_url' => self::PANDASCORE]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    /**
     * Trimmed copies of real PandaScore responses.
     */
    protected function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/pandascore/{$name}.json")), true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function matchFixture(array $overrides = []): array
    {
        return array_replace($this->fixture('match_finished'), $overrides);
    }

    protected function fakeVideogames(): void
    {
        Http::fake([self::PANDASCORE.'/videogames*' => Http::response($this->fixture('videogames'))]);
    }
}
