<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\NewsletterSubscriber;
use App\Matomo\Users\UserDirectoryRepository;
use Tests\TestCase;

final class UsersManagerTokenNewsletterApiTest extends TestCase
{
    public function test_user_creates_secure_expiring_token_for_self(): void
    {
        $this->authorize('alice');
        $directory = $this->createStub(UserDirectoryRepository::class);
        $directory->method('user')->willReturn(['login' => 'alice', 'email' => 'alice@example.test']);
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->once())->method('createToken')
            ->with('alice', 'Mobile app', $this->isType('string'), true)
            ->willReturn(['result' => 'created', 'token' => 'plain-token']);
        $this->app->instance(UserDirectoryRepository::class, $directory);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.createAppSpecificTokenAuth'.
            '&userLogin=alice&passwordConfirmation=correct&description=Mobile%20app'.
            '&expireHours=2&secureOnly=1&format=json&token_auth=alice-token')
            ->assertOk()->assertExactJson(['value' => 'plain-token']);
    }

    public function test_authenticated_user_cannot_create_token_for_other_user(): void
    {
        $this->authorize('alice');
        $directory = $this->createStub(UserDirectoryRepository::class);
        $directory->method('user')->willReturn(['login' => 'bob']);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->never())->method('createToken');
        $this->app->instance(UserDirectoryRepository::class, $directory);
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.createAppSpecificTokenAuth'.
            '&userLogin=bob&passwordConfirmation=correct&description=App'.
            '&format=json&token_auth=alice-token')->assertUnauthorized();
    }

    public function test_authenticated_identity_is_case_sensitive_for_token_creation(): void
    {
        $this->authorize('Alice');
        $directory = $this->createStub(UserDirectoryRepository::class);
        $directory->method('user')->willReturn(['login' => 'alice']);
        $users = $this->createMock(MutableUserRepository::class);
        $users->expects($this->never())->method('createToken');
        $this->app->instance(UserDirectoryRepository::class, $directory);
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.createAppSpecificTokenAuth'.
            '&userLogin=alice&passwordConfirmation=correct&description=App'.
            '&format=json&token_auth=alice-token')->assertUnauthorized();
    }

    public function test_anonymous_request_can_create_token_with_target_credentials(): void
    {
        $this->authorize(null);
        $directory = $this->createStub(UserDirectoryRepository::class);
        $directory->method('user')->willReturn(['login' => 'alice', 'email' => 'alice@example.test']);
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $users = $this->createStub(MutableUserRepository::class);
        $users->method('createToken')->willReturn(['result' => 'created', 'token' => 'plain-token']);
        $this->app->instance(UserDirectoryRepository::class, $directory);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(MutableUserRepository::class, $users);

        $this->get('/index.php?module=API&method=UsersManager.createAppSpecificTokenAuth'.
            '&userLogin=alice&passwordConfirmation=correct&description=App&format=json')
            ->assertOk()->assertExactJson(['value' => 'plain-token']);
    }

    public function test_current_user_subscribes_with_stored_email(): void
    {
        $this->authorize('alice');
        $directory = $this->createStub(UserDirectoryRepository::class);
        $directory->method('user')->willReturn(['login' => 'alice', 'email' => 'alice@example.test']);
        $newsletter = $this->createMock(NewsletterSubscriber::class);
        $newsletter->expects($this->once())->method('subscribe')
            ->with('alice', 'alice@example.test')->willReturn(true);
        $this->app->instance(UserDirectoryRepository::class, $directory);
        $this->app->instance(NewsletterSubscriber::class, $newsletter);

        $this->get('/index.php?module=API&method=UsersManager.newsletterSignup'.
            '&format=json&token_auth=alice-token')->assertOk()->assertExactJson(['success' => true]);
    }

    private function authorize(?string $login): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
