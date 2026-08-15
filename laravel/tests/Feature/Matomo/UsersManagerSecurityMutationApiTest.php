<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Users\MutableUserRepository;
use Tests\TestCase;

final class UsersManagerSecurityMutationApiTest extends TestCase
{
    public function test_superuser_access_change_requires_password_and_clears_cache(): void
    {
        $this->authorize();
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'correct')->willReturn(true);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('setSuperuser')->with('alice', true)->willReturn('updated');
        $cache = $this->createMock(TrackerCacheInvalidator::class);
        $cache->expects($this->once())->method('clearGeneral');
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableUserRepository::class, $users);
        $this->app->instance(TrackerCacheInvalidator::class, $cache);

        $this->get('/index.php?module=API&method=UsersManager.setSuperUserAccess'.
            '&userLogin=alice&hasSuperUserAccess=1&passwordConfirmation=correct'.
            '&format=json&token_auth=super-token')->assertOk();
    }

    public function test_only_superuser_cannot_be_demoted(): void
    {
        $this->authorize();
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $users = $this->createStub(MutableUserRepository::class);
        $users->method('setSuperuser')->willReturn('only-superuser');
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.setSuperUserAccess'.
            '&userLogin=admin&hasSuperUserAccess=0&passwordConfirmation=correct'.
            '&format=json&token_auth=super-token')->assertBadRequest();
    }

    public function test_token_authenticated_logout_does_not_require_password(): void
    {
        $this->authorize();
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->never())->method('isCorrect');
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('deleteSessions')->with('alice')->willReturn(true);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.logoutUser'.
            '&userLogin=alice&format=json&token_auth=super-token')->assertOk();
    }

    private function authorize(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
