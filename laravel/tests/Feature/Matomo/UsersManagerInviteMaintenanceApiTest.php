<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\UserInvitationLinkFactory;
use App\Matomo\Users\UserInvitationNotifier;
use Tests\TestCase;

final class UsersManagerInviteMaintenanceApiTest extends TestCase
{
    public function test_inviter_can_resend_invitation(): void
    {
        $this->authorize(false);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('renewInvitation')
            ->with('alice', 14, false, 'admin', false)
            ->willReturn(['result' => 'updated', 'email' => 'alice@example.test', 'token' => 'token']);
        $notifier = $this->createMock(UserInvitationNotifier::class);
        $notifier->expects($this->once())->method('notify')
            ->with('alice', 'alice@example.test', 'token', 14);
        $this->app->instance(MutableUserRepository::class, $users);
        $this->app->instance(UserInvitationNotifier::class, $notifier);

        $this->get('/index.php?module=API&method=UsersManager.resendInvite'.
            '&userLogin=alice&expiryInDays=14&format=json&token_auth=admin-token')->assertOk();
    }

    public function test_generate_link_returns_url_without_sending_mail(): void
    {
        $this->authorize(true);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('renewInvitation')
            ->with('alice', 7, true, 'admin', true)
            ->willReturn(['result' => 'updated', 'email' => 'alice@example.test', 'token' => 'token']);
        $links = $this->createMock(UserInvitationLinkFactory::class);
        $links->expects($this->once())->method('make')->with('token')->willReturn('https://analytics.test/invite');
        $notifier = $this->createMock(UserInvitationNotifier::class);
        $notifier->expects($this->never())->method('notify');
        $this->app->instance(MutableUserRepository::class, $users);
        $this->app->instance(UserInvitationLinkFactory::class, $links);
        $this->app->instance(UserInvitationNotifier::class, $notifier);

        $this->get('/index.php?module=API&method=UsersManager.generateInviteLink'.
            '&userLogin=alice&format=json&token_auth=admin-token')
            ->assertOk()->assertExactJson(['value' => 'https://analytics.test/invite']);
    }

    public function test_non_inviter_is_denied_without_token_disclosure(): void
    {
        $this->authorize(false);
        $users = $this->createStub(MutableUserRepository::class);
        $users->method('renewInvitation')->willReturn(['result' => 'denied']);
        $links = $this->createMock(UserInvitationLinkFactory::class);
        $links->expects($this->never())->method('make');
        $this->app->instance(MutableUserRepository::class, $users);
        $this->app->instance(UserInvitationLinkFactory::class, $links);

        $this->get('/index.php?module=API&method=UsersManager.generateInviteLink'.
            '&userLogin=alice&format=json&token_auth=admin-token')->assertUnauthorized();
    }

    private function authorize(bool $superuser): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $authorizer->method('hasSuperUserAccess')->willReturn($superuser);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
