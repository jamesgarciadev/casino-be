<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AdminUsersQueryTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const QUERY = /** @lang GraphQL */ '
        query ($search: String, $page: Int, $first: Int!) {
            adminUsers(search: $search, page: $page, first: $first) {
                data { id name email roles }
                paginatorInfo { total currentPage lastPage perPage }
            }
        }
    ';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->adminToken($this->userWithRole(Role::Moderator, ['name' => 'Mod Erator', 'email' => 'mod@staff.test']));
    }

    public function test_search_matches_part_of_a_name(): void
    {
        $this->userWithRole(Role::Customer, ['name' => 'Alice Lucky', 'email' => 'a@example.com']);
        $this->userWithRole(Role::Customer, ['name' => 'Bob Bettor', 'email' => 'b@example.com']);

        $this->listUsers(['search' => 'luck'])
            ->assertJsonCount(1, 'data.adminUsers.data')
            ->assertJsonPath('data.adminUsers.data.0.name', 'Alice Lucky');
    }

    public function test_search_matches_part_of_an_email(): void
    {
        $this->userWithRole(Role::Customer, ['name' => 'Alice', 'email' => 'alice@highroller.test']);
        $this->userWithRole(Role::Customer, ['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->listUsers(['search' => 'highroller'])
            ->assertJsonCount(1, 'data.adminUsers.data')
            ->assertJsonPath('data.adminUsers.data.0.email', 'alice@highroller.test');
    }

    public function test_empty_search_returns_everyone(): void
    {
        User::factory()->count(3)->role(Role::Customer)->create();

        $this->listUsers(['search' => ''])->assertJsonPath('data.adminUsers.paginatorInfo.total', 4);
    }

    public function test_search_treats_wildcards_as_literal_characters(): void
    {
        User::factory()->count(3)->role(Role::Customer)->create();

        $this->listUsers(['search' => '%'])->assertJsonPath('data.adminUsers.paginatorInfo.total', 0);
        $this->listUsers(['search' => '_'])->assertJsonPath('data.adminUsers.paginatorInfo.total', 0);
    }

    public function test_search_with_no_matches_returns_an_empty_page(): void
    {
        $this->listUsers(['search' => 'nobody-by-this-name'])
            ->assertJsonCount(0, 'data.adminUsers.data')
            ->assertJsonPath('data.adminUsers.paginatorInfo.total', 0);
    }

    public function test_users_are_listed_newest_first(): void
    {
        $older = $this->userWithRole(Role::Customer);
        $newer = $this->userWithRole(Role::Customer);

        $ids = $this->listUsers()->json('data.adminUsers.data.*.id');

        $this->assertSame([(string) $newer->id, (string) $older->id], array_slice($ids, 0, 2));
    }

    public function test_results_are_paginated(): void
    {
        User::factory()->count(24)->role(Role::Customer)->create();

        $this->listUsers(['page' => 3, 'first' => 10])
            ->assertJsonCount(5, 'data.adminUsers.data')
            ->assertJsonPath('data.adminUsers.paginatorInfo.total', 25)
            ->assertJsonPath('data.adminUsers.paginatorInfo.currentPage', 3)
            ->assertJsonPath('data.adminUsers.paginatorInfo.lastPage', 3);
    }

    public function test_page_size_is_capped_at_100(): void
    {
        $this->listUsers(['first' => 101])->assertJsonPath('data', null);
    }

    public function test_each_user_reports_their_role(): void
    {
        $this->userWithRole(Role::Owner, ['email' => 'boss@staff.test']);

        $this->listUsers(['search' => 'boss@staff.test'])->assertJsonPath('data.adminUsers.data.0.roles', ['owner']);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function listUsers(array $variables = []): TestResponse
    {
        $this->forgetAuth();

        return $this->withToken($this->token)->graphQL(self::QUERY, $variables + ['first' => 20]);
    }
}
