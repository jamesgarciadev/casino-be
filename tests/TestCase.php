<?php

namespace Tests;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed roles and permissions whenever RefreshDatabase migrates. */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithRole(Role $role, array $attributes = []): User
    {
        return User::factory()->role($role)->create($attributes);
    }

    protected function adminToken(User $user, ?DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('admin-console', [TokenAbility::Admin->value], $expiresAt)->plainTextToken;
    }

    protected function customerToken(User $user, ?DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('web', [TokenAbility::Customer->value], $expiresAt)->plainTextToken;
    }

    protected function simToken(?User $user = null): string
    {
        $user ??= $this->userWithRole(Role::Simulator);

        return $user->createToken('casino-sim', [TokenAbility::Sim->value])->plainTextToken;
    }

    /** Sanctum caches the resolved user per guard, so reset it between requests that switch tokens. */
    protected function forgetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
