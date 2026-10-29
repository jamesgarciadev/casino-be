<?php

namespace App\GraphQL\Mutations;

use App\Services\AuthService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final readonly class Logout
{
    public function __construct(private AuthService $auth) {}

    public function __invoke(null $_, array $args, GraphQLContext $context): bool
    {
        $this->auth->logout($context->user());

        return true;
    }
}
