<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Users\UserDirectoryRepository;
use Tests\TestCase;

final class UsersManagerReadApiTest extends TestCase
{
    public function test_site_admin_only_lists_users_visible_on_administered_sites(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->expects($this->once())->method('siteIdsWithRole')
            ->with($this->anything(), SiteAccessRole::Admin)->willReturn([7]);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->once())->method('users')->with([])->willReturn([
            ['login' => 'admin', 'email' => 'admin@example.test', 'superuser_access' => 0],
            ['login' => 'viewer', 'email' => 'private@example.test', 'superuser_access' => 0],
            ['login' => 'hidden', 'email' => 'hidden@example.test', 'superuser_access' => 0],
        ]);
        $users->expects($this->once())->method('visibleLogins')->with('admin', [7])
            ->willReturn(['admin', 'viewer']);
        $plugins = $this->createStub(PluginState::class);
        $plugins->method('isActivated')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(PluginState::class, $plugins);

        $this->get('/index.php?module=API&method=UsersManager.getUsers'.
            '&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.login', 'admin')
            ->assertJsonPath('0.email', 'admin@example.test')
            ->assertJsonMissing(['email' => 'private@example.test']);
    }

    public function test_superuser_reads_one_user_without_sensitive_credentials(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('root');
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->once())->method('user')->with('alice')->willReturn([
            'login' => 'alice',
            'email' => 'alice@example.test',
            'password' => 'secret-hash',
            'token_auth' => 'secret-token',
            'twofactor_secret' => '2fa-secret',
            'superuser_access' => 0,
        ]);
        $plugins = $this->createStub(PluginState::class);
        $plugins->method('isActivated')->with('TwoFactorAuth')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(PluginState::class, $plugins);

        $this->get('/index.php?module=API&method=UsersManager.getUser'.
            '&userLogin=alice&format=json&token_auth=root-token')
            ->assertOk()
            ->assertJsonPath('login', 'alice')
            ->assertJsonPath('uses_2fa', true)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token_auth')
            ->assertJsonMissingPath('twofactor_secret');
    }

    public function test_non_superuser_cannot_read_another_user_directly(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->never())->method('user');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.getUser'.
            '&userLogin=alice&format=json&token_auth=admin-token')
            ->assertUnauthorized();
    }

    public function test_self_access_requires_the_exact_login_case(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('Alice');
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->never())->method('user');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.getUser'.
            '&userLogin=alice&format=json&token_auth=alice-token')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_list_superusers(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('viewer');
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $users = $this->createMock(UserDirectoryRepository::class);
        $users->expects($this->once())->method('superusers')->willReturn([[
            'login' => 'root',
            'email' => 'root@example.test',
            'superuser_access' => 1,
        ]]);
        $plugins = $this->createStub(PluginState::class);
        $plugins->method('isActivated')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserDirectoryRepository::class, $users);
        $this->app->instance(PluginState::class, $plugins);

        $this->get('/index.php?module=API&method=UsersManager.getUsersHavingSuperUserAccess'.
            '&format=json&token_auth=view-token')
            ->assertOk()
            ->assertJsonPath('0.login', 'root')
            ->assertJsonPath('0.email', 'root@example.test');
    }
}
