<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

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

    public function test_customer_cannot_use_admin_login(): void
    {
        $this->userWithRole('customer', 'cust@example.com');

        $this->graphQL(/** @lang GraphQL */ '
            mutation { adminLogin(input: { email: "cust@example.com", password: "password" }) { token } }
        ')->assertGraphQLErrorMessage('These credentials do not match our records.');
    }

    public function test_staff_cannot_use_customer_login(): void
    {
        $this->userWithRole('admin', 'staff@example.com');

        $this->graphQL(/** @lang GraphQL */ '
            mutation { login(input: { email: "staff@example.com", password: "password" }) { token } }
        ')->assertGraphQLErrorMessage('These credentials do not match our records.');
    }

    public function test_staff_login_issues_admin_token_and_is_audited(): void
    {
        $this->userWithRole('moderator', 'mod@example.com');

        $this->graphQL(/** @lang GraphQL */ '
            mutation { adminLogin(input: { email: "mod@example.com", password: "password" }) { user { roles permissions } } }
        ')->assertJsonPath('data.adminLogin.user.roles', ['moderator'])
            ->assertJsonPath('data.adminLogin.user.permissions', ['users.view']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'admin_login']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->userWithRole('customer', 'cust@example.com');
        $token = $user->createToken('web', ['customer'])->plainTextToken;

        $this->withToken($token)->graphQL('mutation { logout }')->assertJsonPath('data.logout', true);

        $this->assertSame(0, $user->tokens()->count());
    }

    private function userWithRole(string $role, string $email): User
    {
        return User::factory()->create(['email' => $email])->assignRole($role);
    }
}
