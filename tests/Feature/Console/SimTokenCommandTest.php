<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SimToken;
use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_a_sim_token_for_the_simulator_account(): void
    {
        $this->artisan('sim:token')->assertSuccessful();

        $simulator = User::where('email', SimToken::EMAIL)->sole();
        $this->assertSame(['simulator'], $simulator->roleNames());
        $this->assertSame([TokenAbility::Sim->value], $simulator->tokens()->sole()->abilities);
    }

    public function test_revoke_replaces_existing_tokens(): void
    {
        $this->artisan('sim:token')->assertSuccessful();
        $this->artisan('sim:token')->assertSuccessful();
        $this->artisan('sim:token --revoke')->assertSuccessful();

        $this->assertSame(1, User::where('email', SimToken::EMAIL)->sole()->tokens()->count());
        $this->assertSame(1, User::role(Role::Simulator->value)->count());
    }
}
