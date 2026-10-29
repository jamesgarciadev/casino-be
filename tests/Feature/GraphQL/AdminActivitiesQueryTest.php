<?php

namespace Tests\Feature\GraphQL;

use App\Enums\Role;
use App\Services\UserRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class AdminActivitiesQueryTest extends TestCase
{
    use MakesGraphQLRequests, RefreshDatabase;

    private const QUERY = /** @lang GraphQL */ '
        query ($logName: String, $page: Int, $first: Int!) {
            adminActivities(logName: $logName, page: $page, first: $first) {
                data { logName event description causer { email } subjectId properties }
                paginatorInfo { total }
            }
        }
    ';

    public function test_admin_and_owner_can_read_the_audit_log(): void
    {
        activity('admin')->log('Something happened');

        foreach ([Role::Admin, Role::Owner] as $role) {
            $this->forgetAuth();

            $this->withToken($this->adminToken($this->userWithRole($role)))
                ->graphQL(self::QUERY, ['first' => 20])
                ->assertJsonPath('data.adminActivities.paginatorInfo.total', 1);
        }
    }

    public function test_moderator_cannot_read_the_audit_log(): void
    {
        $this->withToken($this->adminToken($this->userWithRole(Role::Moderator)))
            ->graphQL(self::QUERY, ['first' => 20])
            ->assertGraphQLErrorMessage('This action is unauthorized.');
    }

    public function test_role_change_entry_shows_who_did_it_and_the_before_and_after(): void
    {
        $owner = $this->userWithRole(Role::Owner, ['email' => 'owner@staff.test']);
        $target = $this->userWithRole(Role::Customer);
        app(UserRoleService::class)->assign($owner, $target, Role::Admin);

        $entry = $this->withToken($this->adminToken($owner))
            ->graphQL(self::QUERY, ['logName' => 'admin', 'first' => 20])
            ->assertJsonPath('data.adminActivities.data.0.event', 'role_assigned')
            ->assertJsonPath('data.adminActivities.data.0.causer.email', 'owner@staff.test')
            ->assertJsonPath('data.adminActivities.data.0.subjectId', (string) $target->id)
            ->json('data.adminActivities.data.0');

        $this->assertSame(
            ['old' => ['roles' => ['customer']], 'attributes' => ['roles' => ['admin']]],
            json_decode($entry['properties'], true),
        );
    }

    public function test_filtering_by_log_name(): void
    {
        activity('admin')->log('Admin change');
        activity('auth')->log('Sign-in');
        activity('auth')->log('Another sign-in');

        $token = $this->adminToken($this->userWithRole(Role::Admin));

        $this->withToken($token)->graphQL(self::QUERY, ['logName' => 'auth', 'first' => 20])
            ->assertJsonPath('data.adminActivities.paginatorInfo.total', 2);

        $this->forgetAuth();

        $this->withToken($token)->graphQL(self::QUERY, ['logName' => 'admin', 'first' => 20])
            ->assertJsonPath('data.adminActivities.paginatorInfo.total', 1);
    }

    public function test_entries_are_newest_first_and_paginated(): void
    {
        foreach (range(1, 30) as $n) {
            activity('admin')->log("Entry {$n}");
        }

        $this->withToken($this->adminToken($this->userWithRole(Role::Admin)))
            ->graphQL(self::QUERY, ['first' => 25])
            ->assertJsonCount(25, 'data.adminActivities.data')
            ->assertJsonPath('data.adminActivities.data.0.description', 'Entry 30')
            ->assertJsonPath('data.adminActivities.paginatorInfo.total', 30);
    }

    public function test_entry_without_a_causer_or_properties_returns_nulls(): void
    {
        activity('admin')->log('System task');

        $this->withToken($this->adminToken($this->userWithRole(Role::Admin)))
            ->graphQL(self::QUERY, ['first' => 20])
            ->assertJsonPath('data.adminActivities.data.0.causer', null)
            ->assertJsonPath('data.adminActivities.data.0.properties', null);
    }
}
