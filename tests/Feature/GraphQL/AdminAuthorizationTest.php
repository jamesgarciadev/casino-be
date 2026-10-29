<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_fields_require_authentication(): void
    {
        $this->graphQL('{ adminUsers { data { id } } }')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_customer_token_cannot_query_admin_fields_even_for_an_owner(): void
    {
        // The token scope is what matters: an owner holding a customer-scoped token is still blocked.
        $owner = $this->userWithRole('owner');

        $this->withToken($owner->createToken('web', ['customer'])->plainTextToken)
            ->graphQL('{ adminUsers { data { id } } }')
            ->assertGraphQLErrorMessage('This action requires a token with the [admin] ability.');
    }

    public function test_moderator_can_list_users_but_not_activity(): void
    {
        $token = $this->adminToken($this->userWithRole('moderator'));

        $this->withToken($token)
            ->graphQL('{ adminUsers(search: "casino") { data { email roles } paginatorInfo { total } } }')
            ->assertJsonPath('data.adminUsers.paginatorInfo.total', 1);

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->graphQL('{ adminActivities { data { id } } }')
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_admin_cannot_assign_roles(): void
    {
        $target = $this->userWithRole('customer');

        $this->withToken($this->adminToken($this->userWithRole('admin')))
            ->graphQL('mutation ($id: ID!) { adminAssignRole(userId: $id, role: MODERATOR) { id } }', ['id' => $target->id])
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_owner_assigns_role_and_change_is_audit_logged(): void
    {
        $owner = $this->userWithRole('owner');
        $target = $this->userWithRole('customer');

        $this->withToken($this->adminToken($owner))
            ->graphQL('mutation ($id: ID!) { adminAssignRole(userId: $id, role: MODERATOR) { roles } }', ['id' => $target->id])
            ->assertJsonPath('data.adminAssignRole.roles', ['moderator']);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'admin',
            'event' => 'role_assigned',
            'causer_id' => $owner->id,
            'subject_id' => $target->id,
        ]);
    }

    public function test_owner_cannot_change_own_role(): void
    {
        $owner = $this->userWithRole('owner');

        $this->withToken($this->adminToken($owner))
            ->graphQL('mutation ($id: ID!) { adminAssignRole(userId: $id, role: CUSTOMER) { id } }', ['id' => $owner->id])
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    private function userWithRole(string $role): User
    {
        static $n = 0;
        $n++;

        return User::factory()->create(['email' => "{$role}{$n}@casino.test"])->assignRole($role);
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin-console', ['admin'])->plainTextToken;
    }
}
