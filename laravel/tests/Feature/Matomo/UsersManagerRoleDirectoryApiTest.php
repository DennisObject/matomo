<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Users\AccessMetadataProvider;
use App\Matomo\Users\UserDirectoryRepository;
use App\Matomo\Users\UserRoleDirectoryRepository;
use Tests\TestCase;

final class UsersManagerRoleDirectoryApiTest extends TestCase
{
    public function test_anonymous_user_gets_empty_directory(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('authenticatedLogin')->willReturn(null);
        $roles = $this->createMock(UserRoleDirectoryRepository::class);
        $roles->expects($this->never())->method('filtered');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserRoleDirectoryRepository::class, $roles);

        $this->get('/index.php?module=API&method=UsersManager.getUsersPlusRole&idSite=7&format=json')
            ->assertOk()
            ->assertHeader('X-Matomo-Total-Results', '0')
            ->assertExactJson([]);
    }

    public function test_non_admin_only_gets_own_role_and_capabilities(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->method('siteIdsWithRole')->with($this->anything(), SiteAccessRole::Admin)
            ->willReturn([]);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->once())->method('user')->with('alice')->willReturn([
            'login' => 'alice',
            'email' => 'alice@example.test',
            'superuser_access' => 0,
        ]);
        $roles = $this->createMock(UserRoleDirectoryRepository::class);
        $roles->expects($this->once())->method('accessEntries')->with('alice', 7)
            ->willReturn(['view', 'manage_tags']);
        $metadata = $this->createStub(AccessMetadataProvider::class);
        $metadata->method('capabilities')->willReturn([[
            'id' => 'manage_tags', 'name' => '', 'description' => '', 'helpUrl' => '',
            'includedInRoles' => [], 'category' => '',
        ]]);
        $plugins = $this->createStub(PluginState::class);
        $plugins->method('isActivated')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(UserRoleDirectoryRepository::class, $roles);
        $this->app->instance(AccessMetadataProvider::class, $metadata);
        $this->app->instance(PluginState::class, $plugins);

        $this->get('/index.php?module=API&method=UsersManager.getUsersPlusRole'.
            '&idSite=7&format=json&token_auth=alice-token')
            ->assertOk()
            ->assertHeader('X-Matomo-Total-Results', '1')
            ->assertJsonPath('0.role', 'view')
            ->assertJsonPath('0.capabilities.0', 'manage_tags');
    }

    public function test_site_admin_gets_filtered_paginated_directory(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->method('siteIdsWithRole')->willReturn([7, 8]);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->once())->method('visibleLogins')->with('admin', [7, 8])
            ->willReturn(['admin', 'viewer']);
        $roles = $this->createMock(UserRoleDirectoryRepository::class);
        $roles->expects($this->once())->method('filtered')
            ->with(7, 10, 2, 'view', 'some', 'active', ['admin', 'viewer'], 'admin', false)
            ->willReturn([
                'rows' => [[
                    'login' => 'viewer', 'email' => 'private@example.test',
                    'superuser_access' => 0, 'access' => ['write'],
                ]],
                'total' => 4,
            ]);
        $metadata = $this->createStub(AccessMetadataProvider::class);
        $metadata->method('capabilities')->willReturn([]);
        $plugins = $this->createStub(PluginState::class);
        $plugins->method('isActivated')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(UserRoleDirectoryRepository::class, $roles);
        $this->app->instance(AccessMetadataProvider::class, $metadata);
        $this->app->instance(PluginState::class, $plugins);

        $this->get('/index.php?module=API&method=UsersManager.getUsersPlusRole'.
            '&idSite=7&limit=10&offset=2&filter_search=view&filter_access=some'.
            '&filter_status=active&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertHeader('X-Matomo-Total-Results', '4')
            ->assertJsonPath('0.role', 'write')
            ->assertJsonMissing(['email' => 'private@example.test']);
    }
}
