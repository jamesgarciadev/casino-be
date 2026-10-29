<?php

namespace Tests\Feature\Services;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Nuwave\Lighthouse\Exceptions\AuthenticationException;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        $this->auth = app(AuthService::class);
    }

    public function test_register_customer_hashes_the_password_and_assigns_only_the_customer_role(): void
    {
        ['user' => $user] = $this->auth->registerCustomer(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123']);

        $this->assertNotSame('secret123', $user->password);
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertSame(['customer'], $user->roleNames());
    }

    public function test_customer_token_is_named_web_and_expires_in_30_days(): void
    {
        ['user' => $user] = $this->auth->registerCustomer(['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123']);

        $token = $user->tokens()->sole();

        $this->assertSame('web', $token->name);
        $this->assertSame(['customer'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(30)));
    }

    public function test_staff_token_is_named_admin_console_and_expires_in_8_hours(): void
    {
        $this->userWithRole(Role::Admin, ['email' => 'admin@staff.test']);

        ['user' => $user] = $this->auth->loginStaff('admin@staff.test', 'password');

        $token = $user->tokens()->sole();

        $this->assertSame('admin-console', $token->name);
        $this->assertSame(['admin'], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addHours(8)));
    }

    public function test_every_staff_role_can_sign_in_to_the_console(): void
    {
        foreach (Role::staff() as $role) {
            $user = $this->userWithRole($role);

            $this->assertTrue($this->auth->loginStaff($user->email, 'password')['user']->is($user), $role->value);
        }
    }

    public function test_staff_sign_in_is_audited_against_the_staff_member(): void
    {
        $owner = $this->userWithRole(Role::Owner, ['email' => 'owner@staff.test']);

        $this->auth->loginStaff('owner@staff.test', 'password');

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'admin_login',
            'causer_id' => $owner->id,
            'subject_id' => $owner->id,
        ]);
    }

    public function test_user_with_no_role_cannot_sign_in_anywhere(): void
    {
        User::factory()->create(['email' => 'norole@example.com']);

        foreach (['loginCustomer', 'loginStaff'] as $method) {
            try {
                $this->auth->{$method}('norole@example.com', 'password');
                $this->fail("{$method} should have rejected a user with no role");
            } catch (AuthenticationException $e) {
                $this->assertSame('These credentials do not match our records.', $e->getMessage());
            }
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_rejected_sign_in_issues_no_token(): void
    {
        $this->userWithRole(Role::Customer, ['email' => 'cust@example.com']);

        try {
            $this->auth->loginStaff('cust@example.com', 'password');
        } catch (AuthenticationException) {
        }

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
