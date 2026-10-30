<?php

namespace App\Enums;

enum Permission: string
{
    case UsersView = 'users.view';
    case UsersManageRoles = 'users.manage-roles';
    case ActivityView = 'activity.view';
    case MatchesManage = 'matches.manage';
}
