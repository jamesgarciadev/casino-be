<?php

namespace App\GraphQL\Mutations;

use App\Enums\Role;
use App\Models\User;
use App\Services\UserRoleService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class AdminAssignRole
{
    public function __construct(private UserRoleService $roles) {}

    /**
     * Authorization runs before this resolver via @canFind in the schema.
     *
     * @param  array{userId: string, role: string}  $args
     */
    public function __invoke(null $_, array $args, GraphQLContext $context): User
    {
        return $this->roles->assign(
            $context->user(),
            User::findOrFail($args['userId']),
            Role::from($args['role']),
        );
    }
}
