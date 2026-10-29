<?php

namespace App\Enums;

enum Role: string
{
    case Customer = 'customer';
    case Moderator = 'moderator';
    case Admin = 'admin';
    case Owner = 'owner';

    /**
     * Roles allowed to sign in to the admin console (fe).
     *
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::Moderator, self::Admin, self::Owner];
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Customer => [],
            self::Moderator => [Permission::UsersView],
            self::Admin => [Permission::UsersView, Permission::ActivityView],
            self::Owner => Permission::cases(),
        };
    }
}
