<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\UserInvitationNotifier;
use Tests\TestCase;

final class UsersManagerCreateApiTest extends TestCase
{
    public function test_site_admin_creates_user_with_initial_view_access(): void
    {
        $this->authorize([7], false);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('create')
            ->with('alice', 'secret1', 'alice@example.test', false, 7)->willReturn('created');
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.addUser'.
            '&userLogin=alice&password=secret1&email=alice%40example.test'.
            '&initialIdSite=7&format=json&token_auth=admin-token')->assertOk();
    }

    public function test_non_superuser_cannot_create_user_without_initial_site(): void
    {
        $this->authorize([7], false);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->never())->method('create');
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.addUser'.
            '&userLogin=alice&password=secret1&email=alice%40example.test'.
            '&format=json&token_auth=admin-token')->assertBadRequest();
    }

    public function test_invite_stores_token_then_notifies_user(): void
    {
        $this->authorize([7], false);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('invite')
            ->with('alice', 'alice@example.test', 7, 30, 'admin')
            ->willReturn(['result' => 'created', 'token' => 'plain-token']);
        $notifier = $this->createMock(UserInvitationNotifier::class);
        $notifier->expects($this->once())->method('notify')
            ->with('alice', 'alice@example.test', 'plain-token', 30);
        $this->app->instance(MutableUserRepository::class, $users);
        $this->app->instance(UserInvitationNotifier::class, $notifier);

        $this->get('/index.php?module=API&method=UsersManager.inviteUser'.
            '&userLogin=alice&email=alice%40example.test&initialIdSite=7'.
            '&expiryInDays=30&format=json&token_auth=admin-token')->assertOk();
    }

    public function test_creation_rejects_unowned_initial_site_before_storage(): void
    {
        $this->authorize([7], false);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->never())->method('create');
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.addUser'.
            '&userLogin=alice&password=secret1&email=alice%40example.test'.
            '&initialIdSite=8&format=json&token_auth=admin-token')->assertUnauthorized();
    }

    /** @param list<int> $siteIds */
    private function authorize(array $siteIds, bool $superuser): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $authorizer->method('hasSuperUserAccess')->willReturn($superuser);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $authorizer->method('siteIdsWithRole')->with($this->anything(), SiteAccessRole::Admin)
            ->willReturn($siteIds);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
