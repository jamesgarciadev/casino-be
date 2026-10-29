<?php

namespace Tests\Feature\Models;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_scope_matches_name_or_email_case_insensitively(): void
    {
        User::factory()->create(['name' => 'Alice Lucky', 'email' => 'alice@example.com']);
        User::factory()->create(['name' => 'Bob', 'email' => 'bob@lucky.test']);
        User::factory()->create(['name' => 'Carol', 'email' => 'carol@example.com']);

        $this->assertEqualsCanonicalizing(['Alice Lucky', 'Bob'], User::search('LUCKY')->pluck('name')->all());
    }

    public function test_search_scope_ignores_null_and_empty_terms(): void
    {
        User::factory()->count(2)->create();

        $this->assertSame(2, User::search(null)->count());
        $this->assertSame(2, User::search('')->count());
    }

    public function test_user_without_a_role_has_no_roles_or_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $user->roleNames());
        $this->assertSame([], $user->permissionNames());
    }

    public function test_password_and_remember_token_are_hidden_from_serialization(): void
    {
        $user = $this->userWithRole(Role::Customer);

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }
}
