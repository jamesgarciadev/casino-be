<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class RoleTest extends TestCase
{
    public function test_customers_are_not_staff(): void
    {
        $this->assertNotContains(Role::Customer, Role::staff());
        $this->assertSame([Role::Moderator, Role::Admin, Role::Owner], Role::staff());
    }

    public function test_customers_have_no_permissions(): void
    {
        $this->assertSame([], Role::Customer->permissions());
    }

    public function test_only_owners_can_manage_roles(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertSame(
                $role === Role::Owner,
                in_array(Permission::UsersManageRoles, $role->permissions(), true),
                $role->value,
            );
        }
    }

    public function test_each_staff_role_includes_the_permissions_of_the_one_below(): void
    {
        $staff = Role::staff();

        for ($i = 1; $i < count($staff); $i++) {
            $lower = $staff[$i - 1]->permissions();
            $higher = $staff[$i]->permissions();

            $this->assertSame([], array_udiff($lower, $higher, fn ($a, $b) => strcmp($a->value, $b->value)), "{$staff[$i]->value} ⊇ {$staff[$i - 1]->value}");
        }
    }
}
