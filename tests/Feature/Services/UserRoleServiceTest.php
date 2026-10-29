<?php

namespace Tests\Feature\Services;

use App\Enums\Role;
use App\Models\Activity;
use App\Services\UserRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_replaces_the_existing_role_rather_than_adding_one(): void
    {
        $target = $this->userWithRole(Role::Customer);

        app(UserRoleService::class)->assign($this->userWithRole(Role::Owner), $target, Role::Moderator);

        $this->assertSame(['moderator'], $target->fresh()->roleNames());
    }

    public function test_returned_user_already_reflects_the_new_role(): void
    {
        $target = $this->userWithRole(Role::Customer);

        $result = app(UserRoleService::class)->assign($this->userWithRole(Role::Owner), $target, Role::Admin);

        $this->assertSame(['admin'], $result->roleNames());
        $this->assertSame(['users.view', 'activity.view'], $result->permissionNames());
    }

    public function test_demotion_removes_staff_permissions(): void
    {
        $target = $this->userWithRole(Role::Admin);

        app(UserRoleService::class)->assign($this->userWithRole(Role::Owner), $target, Role::Customer);

        $this->assertSame([], $target->fresh()->permissionNames());
    }

    public function test_audit_entry_records_actor_target_and_before_after(): void
    {
        $owner = $this->userWithRole(Role::Owner);
        $target = $this->userWithRole(Role::Moderator);

        app(UserRoleService::class)->assign($owner, $target, Role::Admin);

        $entry = Activity::query()->where('event', 'role_assigned')->sole();

        $this->assertSame('admin', $entry->log_name);
        $this->assertSame('Assigned role [admin]', $entry->description);
        $this->assertTrue($entry->causer->is($owner));
        $this->assertTrue($entry->subject->is($target));
        $this->assertSame(['roles' => ['moderator']], $entry->properties['old']);
        $this->assertSame(['roles' => ['admin']], $entry->properties['attributes']);
    }

    public function test_reassigning_the_same_role_is_still_audited(): void
    {
        $target = $this->userWithRole(Role::Moderator);

        app(UserRoleService::class)->assign($this->userWithRole(Role::Owner), $target, Role::Moderator);

        $this->assertDatabaseCount('activity_log', 1);
        $this->assertSame(['moderator'], $target->fresh()->roleNames());
    }
}
