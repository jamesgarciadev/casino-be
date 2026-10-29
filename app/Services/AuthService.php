<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Nuwave\Lighthouse\Exceptions\AuthenticationException;

class AuthService
{
    private const ADMIN_TOKEN_TTL_HOURS = 8;

    private const CUSTOMER_TOKEN_TTL_DAYS = 30;

    /**
     * @param  array{name: string, email: string, password: string}  $input
     * @return array{token: string, user: User}
     */
    public function registerCustomer(array $input): array
    {
        $user = DB::transaction(function () use ($input) {
            $user = User::create($input);
            $user->assignRole(Role::Customer->value);

            return $user;
        });

        return $this->issueCustomerToken($user);
    }

    /**
     * @return array{token: string, user: User}
     */
    public function loginCustomer(string $email, string $password): array
    {
        $user = $this->verifyCredentials($email, $password);

        if (! $user->hasRole(Role::Customer->value)) {
            throw new AuthenticationException('These credentials do not match our records.');
        }

        return $this->issueCustomerToken($user);
    }

    /**
     * @return array{token: string, user: User}
     */
    public function loginStaff(string $email, string $password): array
    {
        $user = $this->verifyCredentials($email, $password);

        if (! $user->hasAnyRole(array_map(fn (Role $role) => $role->value, Role::staff()))) {
            throw new AuthenticationException('These credentials do not match our records.');
        }

        $token = $user->createToken(
            'admin-console',
            [TokenAbility::Admin->value],
            now()->addHours(self::ADMIN_TOKEN_TTL_HOURS),
        );

        activity('auth')->causedBy($user)->performedOn($user)->event('admin_login')->log('Signed in to admin console');

        return ['token' => $token->plainTextToken, 'user' => $user];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    private function verifyCredentials(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('These credentials do not match our records.');
        }

        return $user;
    }

    /**
     * @return array{token: string, user: User}
     */
    private function issueCustomerToken(User $user): array
    {
        $token = $user->createToken(
            'web',
            [TokenAbility::Customer->value],
            now()->addDays(self::CUSTOMER_TOKEN_TTL_DAYS),
        );

        return ['token' => $token->plainTextToken, 'user' => $user];
    }
}
