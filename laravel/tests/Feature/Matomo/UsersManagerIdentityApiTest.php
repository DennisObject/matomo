<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Users\UserIdentityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UsersManagerIdentityApiTest extends TestCase
{
    public function test_anonymous_login_always_exists_without_authentication(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('authenticatedLogin');
        $users = $this->createMock(UserIdentityRepository::class);
        $users->expects($this->never())->method('loginExists');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserIdentityRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.userExists'.
            '&userLogin=Anonymous&format=json')
            ->assertOk()
            ->assertExactJson(['value' => true]);
    }

    public function test_current_login_exists_without_database_lookup(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('Alice');
        $authorizer->expects($this->once())->method('hasSomeViewAccess')->willReturn(true);
        $users = $this->createMock(UserIdentityRepository::class);
        $users->expects($this->never())->method('loginExists');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserIdentityRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.userExists'.
            '&userLogin=alice&format=json&token_auth=alice-token')
            ->assertOk()
            ->assertExactJson(['value' => true]);
    }

    public function test_viewer_checks_another_login_and_email(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $users = $this->createMock(UserIdentityRepository::class);
        $users->expects($this->once())->method('loginExists')->with('bob')->willReturn(true);
        $users->expects($this->once())->method('emailExists')->with('missing@example.test')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserIdentityRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.userExists'.
            '&userLogin=bob&format=json&token_auth=alice-token')
            ->assertExactJson(['value' => true]);
        $this->get('/index.php?module=API&method=UsersManager.userEmailExists'.
            '&userEmail=missing%40example.test&format=json&token_auth=alice-token')
            ->assertExactJson(['value' => false]);
    }

    public function test_admin_resolves_login_by_email(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $authorizer->expects($this->once())->method('hasSomeAdminAccess')->willReturn(true);
        $users = $this->createMock(UserIdentityRepository::class);
        $users->expects($this->once())->method('loginForEmail')->with('alice@example.test')->willReturn('alice');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserIdentityRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.getUserLoginFromUserEmail'.
            '&userEmail=alice%40example.test&format=json&token_auth=admin-token')
            ->assertOk()
            ->assertExactJson(['value' => 'alice']);
    }

    public function test_superuser_status_is_returned_without_other_access_checks(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->expects($this->never())->method('authenticatedLogin');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get('/index.php?module=API&method=UsersManager.hasSuperUserAccess&format=json')
            ->assertOk()
            ->assertExactJson(['value' => false]);
    }

    #[DataProvider('authenticatedMethods')]
    public function test_identity_queries_reject_anonymous_users(string $method, string $parameter): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('authenticatedLogin')->willReturn(null);
        $users = $this->createMock(UserIdentityRepository::class);
        $users->expects($this->never())->method('loginExists');
        $users->expects($this->never())->method('emailExists');
        $users->expects($this->never())->method('loginForEmail');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(UserIdentityRepository::class, $users);

        $this->get("/index.php?module=API&method={$method}&{$parameter}&format=json")
            ->assertUnauthorized();
    }

    /** @return iterable<string, array{string, string}> */
    public static function authenticatedMethods(): iterable
    {
        yield 'login' => ['UsersManager.userExists', 'userLogin=bob'];
        yield 'email' => ['UsersManager.userEmailExists', 'userEmail=bob%40example.test'];
        yield 'email lookup' => [
            'UsersManager.getUserLoginFromUserEmail',
            'userEmail=bob%40example.test',
        ];
    }
}
