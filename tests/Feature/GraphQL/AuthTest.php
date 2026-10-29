<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    public function test_register_creates_a_customer_with_a_customer_token(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(input: { name: "Jane", email: "jane@example.com", password: "secret123", passwordConfirmation: "secret123" }) {
                    token
                    user { email roles }
                }
            }
        ');

        $response->assertJsonPath('data.register.user.roles', ['customer']);
        $this->assertTrue(User::firstWhere('email', 'jane@example.com')->tokens()->first()->can('customer'));
    }

    public function test_register_returns_field_validation_errors(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(input: { name: "J", email: "not-an-email", password: "short", passwordConfirmation: "other" }) { token }
            }
        ')->assertGraphQLValidationKeys(['input.name', 'input.email', 'input.password']);
    }

    public function test_register_rejects_an_email_that_is_already_taken(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'taken@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(input: { name: "Jane", email: "taken@example.com", password: "secret123", passwordConfirmation: "secret123" }) { token }
            }
        ')->assertGraphQLValidationKeys(['input.email']);

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_register_rejects_mismatched_password_confirmation(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation {
                register(input: { name: "Jane", email: "jane@example.com", password: "secret123", passwordConfirmation: "secret124" }) { token }
            }
        ')->assertGraphQLValidationKeys(['input.password']);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_customer_login_returns_a_customer_token(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'cust@example.com']);

        $token = $this->graphQL(/** @lang GraphQL */ '
            mutation { login(input: { email: "cust@example.com", password: "password" }) { token user { roles } } }
        ')->assertJsonPath('data.login.user.roles', ['customer'])->json('data.login.token');

        $this->assertIsString($token);
    }

    public function test_login_with_wrong_password_or_unknown_email_gives_the_same_error(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'cust@example.com']);

        // Same message either way, so the response doesn't reveal which emails are registered.
        $this->graphQL(/** @lang GraphQL */ '
            mutation { login(input: { email: "cust@example.com", password: "wrong-password" }) { token } }
        ')->assertGraphQLErrorMessage('These credentials do not match our records.');

        $this->graphQL(/** @lang GraphQL */ '
            mutation { login(input: { email: "nobody@example.com", password: "password" }) { token } }
        ')->assertGraphQLErrorMessage('These credentials do not match our records.');
    }

    public function test_customer_cannot_use_admin_login(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'cust@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation { adminLogin(input: { email: "cust@example.com", password: "password" }) { token } }
        ')->assertGraphQLErrorMessage('These credentials do not match our records.');
    }

    public function test_staff_cannot_use_customer_login(): void
    {
        $this->userWithRole(Role::Admin, ['email' => 'staff@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation { login(input: { email: "staff@example.com", password: "password" }) { token } }
        ')->assertGraphQLErrorMessage('These credentials do not match our records.');
    }

    public function test_staff_login_issues_admin_token_and_is_audited(): void
    {
        $this->userWithRole(Role::Moderator, ['email' => 'mod@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation { adminLogin(input: { email: "mod@example.com", password: "password" }) { user { roles permissions } } }
        ')->assertJsonPath('data.adminLogin.user.roles', ['moderator'])
            ->assertJsonPath('data.adminLogin.user.permissions', ['users.view']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'admin_login']);
    }

    public function test_failed_staff_login_is_not_audited_as_a_sign_in(): void
    {
        $this->userWithRole(Role::Owner, ['email' => 'owner@example.com']);

        $this->graphQL(/** @lang GraphQL */ '
            mutation { adminLogin(input: { email: "owner@example.com", password: "wrong-password" }) { token } }
        ')->assertGraphQLErrorMessage('These credentials do not match our records.');

        $this->assertDatabaseMissing('activity_log', ['event' => 'admin_login']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->userWithRole(Role::Customer);

        $this->withToken($this->customerToken($user))->graphQL('mutation { logout }')->assertJsonPath('data.logout', true);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_logout_keeps_the_users_other_sessions(): void
    {
        $user = $this->userWithRole(Role::Customer);
        $this->customerToken($user);

        $this->withToken($this->customerToken($user))->graphQL('mutation { logout }')->assertJsonPath('data.logout', true);

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_logout_requires_authentication(): void
    {
        $this->graphQL('mutation { logout }')->assertGraphQLErrorMessage('Unauthenticated.');
    }
}
