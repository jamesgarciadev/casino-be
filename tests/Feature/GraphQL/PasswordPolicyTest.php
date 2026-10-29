<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Pa1!', 'Password must be between 8 and 64 characters.'],
            'too long' => ['Pa1!'.str_repeat('a', 61), 'Password must be between 8 and 64 characters.'],
            'contains a space' => ['Pass word1!', 'Password must not contain spaces.'],
            'no uppercase' => ['password1!', 'Password must contain at least one uppercase letter.'],
            'no lowercase' => ['PASSWORD1!', 'Password must contain at least one lowercase letter.'],
            'no number' => ['Password!!', 'Password must contain at least one number.'],
            'no special character' => ['Password12', 'Password must contain at least one special character.'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_register_rejects_weak_passwords(string $password, string $message): void
    {
        $this->register($password)
            ->assertGraphQLValidationKeys(['input.password'])
            ->assertJsonFragment([$message]);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_register_lists_every_unmet_requirement(): void
    {
        $messages = $this->register('abc')->json('errors.0.extensions.validation')['input.password'];

        $this->assertCount(4, $messages); // length, uppercase, number, special
    }

    public function test_register_accepts_a_password_at_each_length_boundary(): void
    {
        $this->register('Passwo1!')->assertJsonPath('errors', null);

        $this->register('Pa1!'.str_repeat('a', 60), 'max@example.com')->assertJsonPath('errors', null);
    }

    public function test_demo_password_satisfies_the_policy(): void
    {
        $this->register('Password1!')->assertJsonPath('data.register.user.email', 'jane@example.com');
    }

    public function test_login_does_not_apply_the_policy(): void
    {
        // Older accounts with weaker passwords must still be able to sign in.
        $this->userWithRole(Role::Customer, ['email' => 'legacy@example.com', 'password' => 'legacy']);

        $this->graphQL('mutation { login(input: { email: "legacy@example.com", password: "legacy" }) { token } }')
            ->assertJsonPath('errors', null);
    }

    private function register(string $password, string $email = 'jane@example.com'): TestResponse
    {
        return $this->graphQL(
            'mutation ($input: RegisterInput!) { register(input: $input) { user { email } } }',
            ['input' => ['name' => 'Jane', 'email' => $email, 'password' => $password, 'passwordConfirmation' => $password]],
        );
    }
}
