<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class MeQueryTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const QUERY = '{ me { email roles permissions } }';

    public function test_me_requires_authentication(): void
    {
        $this->graphQL(self::QUERY)->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_me_works_with_a_customer_token(): void
    {
        $customer = $this->userWithRole(Role::Customer);

        $this->withToken($this->customerToken($customer))
            ->graphQL(self::QUERY)
            ->assertJsonPath('data.me.email', $customer->email)
            ->assertJsonPath('data.me.roles', ['customer'])
            ->assertJsonPath('data.me.permissions', []);
    }

    public function test_me_reports_each_staff_roles_permissions(): void
    {
        $expected = [
            'moderator' => ['users.view'],
            'admin' => ['users.view', 'activity.view'],
            'owner' => ['users.view', 'users.manage-roles', 'activity.view'],
        ];

        foreach ($expected as $role => $permissions) {
            $this->forgetAuth();

            $permissionsReturned = $this->withToken($this->adminToken($this->userWithRole(Role::from($role))))
                ->graphQL(self::QUERY)
                ->assertJsonPath('data.me.roles', [$role])
                ->json('data.me.permissions');

            $this->assertEqualsCanonicalizing($permissions, $permissionsReturned, "Permissions for {$role}");
        }
    }

    public function test_revoked_token_no_longer_resolves_me(): void
    {
        $customer = $this->userWithRole(Role::Customer);
        $token = $this->customerToken($customer);
        $customer->tokens()->delete();

        $this->withToken($token)->graphQL(self::QUERY)->assertGraphQLErrorMessage('Unauthenticated.');
    }
}
