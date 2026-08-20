<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Users\AccessMetadataProvider;
use App\Matomo\Users\UserDirectoryRepository;
use App\Matomo\Users\UserSiteAccessRepository;
use Tests\TestCase;

final class UsersManagerSiteAccessApiTest extends TestCase
{
    public function test_superuser_lists_sites_by_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $access = $this->createMock(UserSiteAccessRepository::class);
        $access->expects($this->once())->method('sitesByLogin')->with('view')
            ->willReturn(['alice' => [1, 2]]);
        $metadata = $this->createStub(AccessMetadataProvider::class);
        $metadata->method('capabilities')->willReturn([]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserSiteAccessRepository::class, $access);
        $this->app->instance(AccessMetadataProvider::class, $metadata);

        $this->get('/index.php?module=API&method=UsersManager.getUsersSitesFromAccess'.
            '&access=view&format=json&token_auth=root-token')
            ->assertOk()
            ->assertExactJson(['alice' => [1, 2]]);
    }

    public function test_site_admin_lists_access_entries_for_the_site(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->expects($this->once())->method('siteIdsWithRole')
            ->with($this->anything(), SiteAccessRole::Admin)->willReturn([7]);
        $access = $this->createMock(UserSiteAccessRepository::class);
        $access->expects($this->once())->method('accessByLogin')->with(7)
            ->willReturn(['alice' => 'view']);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserSiteAccessRepository::class, $access);

        $this->get('/index.php?module=API&method=UsersManager.getUsersAccessFromSite'.
            '&idSite=7&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertExactJson(['alice' => 'view']);
    }

    public function test_superuser_target_receives_virtual_admin_access_for_all_sites(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->once())->method('user')->with('root')
            ->willReturn(['login' => 'root', 'superuser_access' => 1]);
        $sites = $this->createMock(SiteRepository::class);
        $sites->expects($this->once())->method('allIds')->willReturn([2, 5]);
        $access = $this->createMock(UserSiteAccessRepository::class);
        $access->expects($this->never())->method('forUser');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(UserSiteAccessRepository::class, $access);

        $this->get('/index.php?module=API&method=UsersManager.getSitesAccessFromUser'.
            '&userLogin=root&format=json&token_auth=root-token')
            ->assertOk()
            ->assertExactJson([
                ['site' => 2, 'access' => 'admin'],
                ['site' => 5, 'access' => 'admin'],
            ]);
    }

    public function test_invalid_access_is_rejected_before_query(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(true);
        $access = $this->createMock(UserSiteAccessRepository::class);
        $access->expects($this->never())->method('sitesByLogin');
        $metadata = $this->createStub(AccessMetadataProvider::class);
        $metadata->method('capabilities')->willReturn([]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserSiteAccessRepository::class, $access);
        $this->app->instance(AccessMetadataProvider::class, $metadata);

        $this->get('/index.php?module=API&method=UsersManager.getUsersSitesFromAccess'.
            '&access=owner&format=json&token_auth=root-token')
            ->assertBadRequest();
    }

    public function test_admin_filters_paginated_access_for_non_superuser(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(true);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->expects($this->once())->method('siteIdsWithRole')
            ->with($this->anything(), SiteAccessRole::Admin)->willReturn([2, 5]);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->once())->method('user')->with('alice')
            ->willReturn(['login' => 'alice', 'superuser_access' => 0]);
        $access = $this->createMock(UserSiteAccessRepository::class);
        $access->expects($this->once())->method('filteredForUser')
            ->with('alice', 10, 2, 'shop', 'some', [2, 5])
            ->willReturn([
                'rows' => [[
                    'idsite' => 5,
                    'site_name' => 'Shop',
                    'access' => ['write', 'manage_tags'],
                ]],
                'total' => 3,
                'hasSome' => true,
            ]);
        $metadata = $this->createStub(AccessMetadataProvider::class);
        $metadata->method('capabilities')->willReturn([[
            'id' => 'manage_tags',
            'name' => 'Manage tags',
            'description' => '',
            'helpUrl' => '',
            'includedInRoles' => [],
            'category' => 'Plugin',
        ]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(UserSiteAccessRepository::class, $access);
        $this->app->instance(AccessMetadataProvider::class, $metadata);

        $this->get('/index.php?module=API&method=UsersManager.getSitesAccessForUser'.
            '&userLogin=alice&limit=10&offset=2&filter_search=shop&filter_access=some'.
            '&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertHeader('X-Matomo-Total-Results', '3')
            ->assertHeader('X-Matomo-Has-Some', '1')
            ->assertExactJson([[
                'idsite' => 5,
                'site_name' => 'Shop',
                'role' => 'write',
                'capabilities' => ['manage_tags'],
            ]]);
    }
}
