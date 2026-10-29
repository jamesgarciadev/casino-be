<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class ActivityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ActivityView->value);
    }
}
