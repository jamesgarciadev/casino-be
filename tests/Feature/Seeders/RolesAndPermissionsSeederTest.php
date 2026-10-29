<?php

namespace Tests\Feature\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_roles_match_the_role_enum(): void
    {
        foreach (Role::cases() as $role) {
            $stored = RoleModel::findByName($role->value, 'web')->permissions->pluck('name')->all();
            $expected = array_map(fn (Permission $p) => $p->value, $role->permissions());

            $this->assertEqualsCanonicalizing($expected, $stored, "Permissions for {$role->value}");
        }
    }

    public function test_seeder_can_run_again_without_duplicating_rows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseCount('roles', count(Role::cases()));
        $this->assertDatabaseCount('permissions', count(Permission::cases()));
    }
}
