<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (! app()->isLocal()) {
            return;
        }

        // Local demo accounts, one per role. Password: "Password1!". Re-running resets them.
        foreach (Role::cases() as $role) {
            User::updateOrCreate(
                ['email' => "{$role->value}@casino.test"],
                ['name' => ucfirst($role->value).' Demo', 'password' => 'Password1!', 'email_verified_at' => now()],
            )->syncRoles([$role->value]);
        }
    }
}
