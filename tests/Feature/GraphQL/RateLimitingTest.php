<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    public function test_login_is_limited_to_5_attempts_per_minute(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->failedLogin('login')->assertGraphQLErrorMessage('These credentials do not match our records.');
        }

        $this->failedLogin('login')->assertGraphQLErrorMessage('Rate limit for Mutation.login exceeded. Try again later.');
    }

    public function test_limit_resets_after_a_minute(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'cust@example.com']);

        foreach (range(1, 6) as $attempt) {
            $this->failedLogin('login');
        }

        $this->travel(61)->seconds();

        $this->graphQL('mutation { login(input: { email: "cust@example.com", password: "Password1!" }) { token } }')
            ->assertJsonPath('errors', null);
    }

    public function test_each_auth_mutation_has_its_own_limit(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->failedLogin('login');
        }

        // Failed customer sign-ins must not lock the same IP out of staff sign-in or registration.
        $this->failedLogin('adminLogin')->assertGraphQLErrorMessage('These credentials do not match our records.');

        $this->graphQL(/** @lang GraphQL */ '
            mutation { register(input: { name: "Jane", email: "jane@example.com", password: "Secret123!", passwordConfirmation: "Secret123!" }) { token } }
        ')->assertJsonPath('errors', null);
    }

    public function test_limit_is_tracked_per_client_ip_behind_the_proxy(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->failedLogin('login', '203.0.113.10');
        }

        $this->failedLogin('login', '203.0.113.10')->assertGraphQLErrorMessage('Rate limit for Mutation.login exceeded. Try again later.');
        $this->failedLogin('login', '203.0.113.20')->assertGraphQLErrorMessage('These credentials do not match our records.');
    }

    private function failedLogin(string $mutation, ?string $clientIp = null): TestResponse
    {
        $headers = $clientIp ? ['X-Forwarded-For' => $clientIp] : [];

        return $this->withHeaders($headers)->graphQL(
            "mutation { {$mutation}(input: { email: \"nobody@example.com\", password: \"wrong-password\" }) { token } }",
        );
    }
}
