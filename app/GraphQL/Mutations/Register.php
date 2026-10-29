<?php

namespace App\GraphQL\Mutations;

use App\Services\AuthService;

final readonly class Register
{
    public function __construct(private AuthService $auth) {}

    /**
     * @param  array{name: string, email: string, password: string}  $args
     */
    public function __invoke(null $_, array $args): array
    {
        return $this->auth->registerCustomer([
            'name' => $args['name'],
            'email' => $args['email'],
            'password' => $args['password'],
        ]);
    }
}
