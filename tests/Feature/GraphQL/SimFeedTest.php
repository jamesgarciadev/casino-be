<?php

namespace Tests\Feature\GraphQL;

use App\Enums\MatchSource;
use App\Enums\Role;
use App\Enums\Side;
use App\Enums\TokenAbility;
use App\Models\Fixture;
use App\Services\BettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class SimFeedTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const CREATE = /** @lang GraphQL */ '
        mutation {
            simCreateMatch(input: {
                externalId: "1759549", game: "valorant", gameName: "Valorant", tournament: "VCT Pacific",
                teamA: "Paper Rex", teamB: "DRX", teamAAcronym: "PRX", oddsA: 1.72, oddsB: 2.10,
                startsAt: "2026-10-30T12:00:00+00:00", streamUrl: "https://twitch.tv/valorant"
            }) { id state oddsA teamAAcronym }
        }
    ';

    private const UPDATE = /** @lang GraphQL */ '
        mutation ($id: ID!, $input: SimUpdateMatchInput!) {
            simUpdateMatch(id: $id, input: $input) { scoreA scoreB oddsA oddsB state winner }
        }
    ';

    private function update(Fixture $fixture, array $input, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->simToken())->graphQL(self::UPDATE, ['id' => $fixture->id, 'input' => $input]);
    }

    public function test_sim_creates_a_match(): void
    {
        $this->withToken($this->simToken())->graphQL(self::CREATE)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('data.simCreateMatch.state', 'UPCOMING')
            ->assertJsonPath('data.simCreateMatch.oddsA', 1.72)
            ->assertJsonPath('data.simCreateMatch.teamAAcronym', 'PRX');

        $this->assertSame(MatchSource::Sim, Fixture::sole()->source);
        $this->assertSame(1759549, Fixture::sole()->external_id);
    }

    public function test_sim_plays_a_match_through_to_settlement(): void
    {
        $fixture = Fixture::factory()->create(['odds_a' => '1.50']);
        $customer = $this->userWithRole(Role::Customer);
        app(BettingService::class)->place($customer, $fixture, Side::A, 100);
        $token = $this->simToken();

        $this->update($fixture, ['state' => 'LIVE', 'scoreA' => 3, 'scoreB' => 1, 'oddsA' => 1.20, 'oddsB' => 4.10], $token)
            ->assertJsonPath('data.simUpdateMatch', ['scoreA' => 3, 'scoreB' => 1, 'oddsA' => 1.2, 'oddsB' => 4.1, 'state' => 'LIVE', 'winner' => null]);

        $this->update($fixture, ['state' => 'FINISHED', 'scoreA' => 13, 'winner' => 'A'], $token)
            ->assertJsonPath('data.simUpdateMatch.winner', 'A');

        // Bet was placed at 1.50, not the in-play 1.20.
        $this->assertSame(1000 - 100 + 150, $customer->wallet->refresh()->balance);
    }

    public function test_state_never_moves_backwards(): void
    {
        $this->update(Fixture::factory()->live()->create(), ['state' => 'UPCOMING'])
            ->assertGraphQLErrorMessage('Match cannot move from live to upcoming.');
    }

    public function test_finishing_requires_a_winner_and_a_winner_requires_finishing(): void
    {
        $fixture = Fixture::factory()->live()->create();
        $token = $this->simToken();
        $message = 'A winner is required when, and only when, a match finishes.';

        $this->update($fixture, ['state' => 'FINISHED'], $token)->assertGraphQLErrorMessage($message);
        $this->update($fixture, ['winner' => 'B'], $token)->assertGraphQLErrorMessage($message);
    }

    public function test_finished_matches_are_frozen(): void
    {
        $this->update(Fixture::factory()->finished()->create(), ['scoreA' => 99])
            ->assertGraphQLErrorMessage('Match is already finished.');
    }

    public function test_a_new_match_cannot_start_finished(): void
    {
        $this->withToken($this->simToken())->graphQL(str_replace('streamUrl:', 'state: FINISHED, streamUrl:', self::CREATE))
            ->assertGraphQLErrorMessage('A new match cannot start as finished.');
    }

    public function test_odds_must_be_above_1(): void
    {
        $this->update(Fixture::factory()->create(), ['oddsA' => 1.0])
            ->assertGraphQLErrorMessage('Validation failed for the field [simUpdateMatch].');
    }

    public function test_only_sim_tokens_can_write_matches(): void
    {
        $fixture = Fixture::factory()->create();
        $owner = $this->userWithRole(Role::Owner);
        $customer = $this->userWithRole(Role::Customer);

        $this->update($fixture, ['scoreA' => 1], $this->adminToken($owner))
            ->assertGraphQLErrorMessage('This action requires a token with the [sim] ability.');
        $this->forgetAuth();
        $this->update($fixture, ['scoreA' => 1], $this->customerToken($customer))
            ->assertGraphQLErrorMessage('This action requires a token with the [sim] ability.');
    }

    public function test_a_sim_token_without_the_simulator_role_is_refused(): void
    {
        $customer = $this->userWithRole(Role::Customer);
        $token = $customer->createToken('forged', [TokenAbility::Sim->value])->plainTextToken;

        $this->update(Fixture::factory()->create(), ['scoreA' => 1], $token)
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_sim_tokens_cannot_use_admin_fields(): void
    {
        $this->withToken($this->simToken())->graphQL('{ adminUsers(first: 1) { data { id } } }')
            ->assertGraphQLErrorMessage('This action requires a token with the [admin] ability.');
    }

    public function test_the_simulator_account_cannot_sign_in(): void
    {
        $this->userWithRole(Role::Simulator, ['email' => 'sim@example.com']);

        foreach (['login', 'adminLogin'] as $mutation) {
            $this->graphQL("mutation { {$mutation}(input: { email: \"sim@example.com\", password: \"Password1!\" }) { token } }")
                ->assertGraphQLErrorMessage('These credentials do not match our records.');
        }
    }
}
