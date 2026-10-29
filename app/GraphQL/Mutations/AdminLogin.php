<?php

namespace App\GraphQL\Mutations;

use App\Services\AuthService;

final readonly class AdminLogin
{
    public function __construct(private AuthService $auth) {}

    /**
     * @param  array{email: string, password: string}  $args
     */
    public function __invoke(null $_, array $args): array
    {
        return $this->auth->loginStaff($args['email'], $args['password']);
    }
}
