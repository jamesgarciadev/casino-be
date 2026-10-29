<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const ASSIGN_ROLE = /** @lang GraphQL */ '
        mutation ($id: ID!, $role: Role!) { adminAssignRole(userId: $id, role: $role) { id roles } }
    ';

    public function test_admin_fields_require_authentication(): void
    {
        $this->graphQL('{ adminUsers { data { id } } }')->assertGraphQLErrorMessage('Unauthenticated.');
        $this->graphQL('{ adminActivities { data { id } } }')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_customer_token_cannot_query_admin_fields_even_for_an_owner(): void
    {
        // The token scope is what matters: an owner holding a customer-scoped token is still blocked.
        $owner = $this->userWithRole(Role::Owner);

        $this->withToken($this->customerToken($owner))
            ->graphQL('{ adminUsers { data { id } } }')
            ->assertGraphQLErrorMessage('This action requires a token with the [admin] ability.');
    }

    public function test_admin_token_without_staff_permissions_is_still_denied(): void
    {
        // Defence in depth: if a customer somehow held an admin-scoped token, the policy still says no.
        $customer = $this->userWithRole(Role::Customer);

        $this->withToken($this->adminToken($customer))
            ->graphQL('{ adminUsers { data { id } } }')
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_moderator_can_list_users_but_not_activity(): void
    {
        $moderator = $this->userWithRole(Role::Moderator);
        $token = $this->adminToken($moderator);

        $this->withToken($token)
            ->graphQL('query ($q: String) { adminUsers(search: $q) { data { email } } }', ['q' => $moderator->email])
            ->assertJsonPath('data.adminUsers.data.0.email', $moderator->email);

        $this->forgetAuth();

        $this->withToken($token)
            ->graphQL('{ adminActivities { data { id } } }')
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_moderator_and_admin_cannot_assign_roles(): void
    {
        $target = $this->userWithRole(Role::Customer);

        foreach ([Role::Moderator, Role::Admin] as $role) {
            $this->forgetAuth();

            $this->withToken($this->adminToken($this->userWithRole($role)))
                ->graphQL(self::ASSIGN_ROLE, ['id' => $target->id, 'role' => 'MODERATOR'])
                ->assertGraphQLErrorMessage('This action is unauthorized.');
        }

        $this->assertSame(['customer'], $target->fresh()->roleNames());
        $this->assertDatabaseMissing('activity_log', ['event' => 'role_assigned']);
    }

    public function test_owner_assigns_role_and_change_is_audit_logged(): void
    {
        $owner = $this->userWithRole(Role::Owner);
        $target = $this->userWithRole(Role::Customer);

        $this->withToken($this->adminToken($owner))
            ->graphQL(self::ASSIGN_ROLE, ['id' => $target->id, 'role' => 'MODERATOR'])
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
        $owner = $this->userWithRole(Role::Owner);

        $this->withToken($this->adminToken($owner))
            ->graphQL(self::ASSIGN_ROLE, ['id' => $owner->id, 'role' => 'CUSTOMER'])
            ->assertGraphQLErrorMessage('This action is unauthorized.');

        $this->assertSame(['owner'], $owner->fresh()->roleNames());
    }

    public function test_assigning_a_role_to_an_unknown_user_fails_without_side_effects(): void
    {
        $this->withToken($this->adminToken($this->userWithRole(Role::Owner)))
            ->graphQL(self::ASSIGN_ROLE, ['id' => 999999, 'role' => 'ADMIN'])
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('activity_log', ['event' => 'role_assigned']);
    }

    public function test_assigning_an_unknown_role_is_rejected_by_the_schema(): void
    {
        $target = $this->userWithRole(Role::Customer);

        $this->withToken($this->adminToken($this->userWithRole(Role::Owner)))
            ->graphQL(self::ASSIGN_ROLE, ['id' => $target->id, 'role' => 'SUPERUSER'])
            ->assertJsonPath('data', null);

        $this->assertSame(['customer'], $target->fresh()->roleNames());
    }
}
