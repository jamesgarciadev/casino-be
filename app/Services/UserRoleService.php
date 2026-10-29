<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserRoleService
{
    /**
     * Replace a user's role and write an audit entry with the before/after state.
     */
    public function assign(User $actor, User $target, Role $role): User
    {
        return DB::transaction(function () use ($actor, $target, $role) {
            $before = $target->roleNames();

            $target->syncRoles([$role->value]);
            $target->load('roles');

            activity('admin')
                ->causedBy($actor)
                ->performedOn($target)
                ->event('role_assigned')
                ->withProperties([
                    'old' => ['roles' => $before],
                    'attributes' => ['roles' => $target->roleNames()],
                ])
                ->log("Assigned role [{$role->value}]");

            return $target;
        });
    }
}
