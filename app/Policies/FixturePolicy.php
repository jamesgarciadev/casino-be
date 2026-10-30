<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class FixturePolicy
{
    /**
     * Create and update matches (casino-sim's feed).
     */
    public function manage(User $actor): bool
    {
        return $actor->can(Permission::MatchesManage->value);
    }
}
