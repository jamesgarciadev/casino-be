<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class TokenScopeTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const ADMIN_QUERY = '{ adminUsers { data { id } } }';

    public function test_admin_login_token_expires_after_8_hours(): void
    {
        $this->userWithRole(Role::Owner, ['email' => 'owner@staff.test']);

        $token = $this->graphQL(/** @lang GraphQL */ '
            mutation { adminLogin(input: { email: "owner@staff.test", password: "password" }) { token } }
        ')->json('data.adminLogin.token');

        $this->travel(8)->hours();
        $this->travel(-1)->minutes();
        $this->forgetAuth();
        $this->withToken($token)->graphQL(self::ADMIN_QUERY)->assertJsonPath('errors', null);

        $this->travel(2)->minutes();
        $this->forgetAuth();
        $this->withToken($token)->graphQL(self::ADMIN_QUERY)->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_customer_login_token_expires_after_30_days(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'cust@example.com']);

        $token = $this->graphQL(/** @lang GraphQL */ '
            mutation { login(input: { email: "cust@example.com", password: "password" }) { token } }
        ')->json('data.login.token');

        $this->travel(29)->days();
        $this->forgetAuth();
        $this->withToken($token)->graphQL('{ me { id } }')->assertJsonPath('errors', null);

        $this->travel(2)->days();
        $this->forgetAuth();
        $this->withToken($token)->graphQL('{ me { id } }')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_issued_tokens_carry_exactly_one_ability(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'cust@example.com']);
        $this->userWithRole(Role::Admin, ['email' => 'admin@staff.test']);

        $this->graphQL('mutation { login(input: { email: "cust@example.com", password: "password" }) { token } }');
        $this->graphQL('mutation { adminLogin(input: { email: "admin@staff.test", password: "password" }) { token } }');

        $abilities = PersonalAccessToken::query()->orderBy('id')->pluck('abilities')->all();

        $this->assertSame([['customer'], ['admin']], $abilities);
    }

    public function test_session_login_without_a_personal_access_token_is_rejected(): void
    {
        // Sanctum hands session-authenticated users a TransientToken whose can() always returns true.
        $this->actingAs($this->userWithRole(Role::Owner), 'web');

        $this->graphQL(self::ADMIN_QUERY)->assertGraphQLErrorMessage('This action requires a token with the [admin] ability.');
    }

    public function test_customer_scoped_token_cannot_call_admin_mutations(): void
    {
        $owner = $this->userWithRole(Role::Owner);
        $target = $this->userWithRole(Role::Customer);

        $this->withToken($this->customerToken($owner))
            ->graphQL('mutation ($id: ID!) { adminAssignRole(userId: $id, role: OWNER) { id } }', ['id' => $target->id])
            ->assertGraphQLErrorMessage('This action requires a token with the [admin] ability.');

        $this->assertSame(['customer'], $target->fresh()->roleNames());
    }
}
