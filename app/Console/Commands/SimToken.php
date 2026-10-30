<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('sim:token {--revoke : Delete the simulator\'s existing tokens first}')]
#[Description('Issue a `sim`-scoped API token for casino-sim (shown once)')]
class SimToken extends Command
{
    public const EMAIL = 'simulator@casino.internal';

    public function handle(): int
    {
        // No usable password: the account exists only to own sim tokens, and login/adminLogin reject its role anyway.
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'casino-sim', 'password' => Str::random(64)],
        );
        $user->syncRoles([Role::Simulator->value]);

        if ($this->option('revoke')) {
            $user->tokens()->delete();
        }

        $token = $user->createToken('casino-sim', [TokenAbility::Sim->value])->plainTextToken;

        $this->info('Put this in casino-sim/.env as SIM_TOKEN (it is not shown again):');
        $this->line($token);

        return self::SUCCESS;
    }
}
