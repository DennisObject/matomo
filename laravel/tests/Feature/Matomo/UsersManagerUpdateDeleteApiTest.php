<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\UserInvitationNotifier;
use Tests\TestCase;

final class UsersManagerUpdateDeleteApiTest extends TestCase
{
    public function test_user_updates_own_email_and_pending_invite_is_resent(): void
    {
        $this->authorize('alice', false, false);
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('update')
            ->with('alice', null, 'new@example.test', false, 7)
            ->willReturn([
                'result' => 'updated', 'emailChanged' => true, 'passwordChanged' => false,
                'email' => 'new@example.test', 'inviteToken' => 'token',
            ]);
        $notifier = $this->createMock(UserInvitationNotifier::class);
        $notifier->expects($this->once())->method('notify')
            ->with('alice', 'new@example.test', 'token', 7);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableUserRepository::class, $users);
        $this->app->instance(UserInvitationNotifier::class, $notifier);
        $this->app->instance(TrackerCacheInvalidator::class, $this->createStub(TrackerCacheInvalidator::class));

        $this->get('/index.php?module=API&method=UsersManager.updateUser'.
            '&userLogin=alice&email=new%40example.test&passwordConfirmation=correct'.
            '&format=json&token_auth=alice-token')->assertOk();
    }

    public function test_user_cannot_update_another_account(): void
    {
        $this->authorize('alice', false, false);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->never())->method('update');
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.updateUser'.
            '&userLogin=bob&email=new%40example.test&format=json&token_auth=alice-token')
            ->assertUnauthorized();
    }

    public function test_admin_only_sees_not_found_when_deleting_unowned_user(): void
    {
        $this->authorize('admin', false, true);
        $users = $this->createStub(MutableUserRepository::class);
        $users->method('delete')->willReturn('denied');
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.deleteUser'.
            '&userLogin=alice&format=json&token_auth=admin-token')->assertNotFound();
    }

    private function authorize(string $login, bool $superuser, bool $someAdmin): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSuperUserAccess')->willReturn($superuser);
        $authorizer->method('hasSomeAdminAccess')->willReturn($someAdmin);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
