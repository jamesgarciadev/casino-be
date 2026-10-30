<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::UsersView->value);
    }

    public function assignRole(User $actor, User $target): bool
    {
        if (! $actor->can(Permission::UsersManageRoles->value)) {
            return false;
        }

        // casino-sim's service account keeps its role; turning it into a player or staff would let a sim token act as one.
        if ($target->hasRole(Role::Simulator->value)) {
            return false;
        }

        // Owners cannot demote themselves and lock the console out.
        return ! ($actor->is($target) && $actor->hasRole(Role::Owner->value));
    }
}
