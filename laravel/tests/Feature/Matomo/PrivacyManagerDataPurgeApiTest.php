<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Privacy\DataPurger;
use Tests\TestCase;

final class PrivacyManagerDataPurgeApiTest extends TestCase
{
    public function test_superuser_executes_purge_after_password_confirmation(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'correct')->willReturn(true);
        $purger = $this->createMock(DataPurger::class);
        $purger->expects($this->once())->method('purge');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(DataPurger::class, $purger);

        $this->get('/index.php?module=API&method=PrivacyManager.executeDataPurge'.
            '&passwordConfirmation=correct&format=json&token_auth=super-token')
            ->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_invalid_password_does_not_execute_purge(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(false);
        $purger = $this->createMock(DataPurger::class);
        $purger->expects($this->never())->method('purge');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(DataPurger::class, $purger);

        $this->get('/index.php?module=API&method=PrivacyManager.executeDataPurge'.
            '&passwordConfirmation=wrong&format=json&token_auth=super-token')->assertForbidden();
    }
}
