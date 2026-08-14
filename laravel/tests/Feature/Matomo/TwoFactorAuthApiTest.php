<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\Events\PasswordConfirmationRequirementChecking;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use App\Matomo\TwoFactorAuth\TwoFactorAuthenticationResetter;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TwoFactorAuthApiTest extends TestCase
{
    public function test_superuser_can_reset_two_factor_auth_after_password_confirmation(): void
    {
        $this->authenticateSuperuser();
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'correct')->willReturn(true);
        $resetter = $this->createMock(TwoFactorAuthenticationResetter::class);
        $resetter->expects($this->once())->method('reset')->with('alice');
        $attempts = $this->createMock(LoginAttemptGuard::class);
        $attempts->expects($this->once())->method('status')->with($this->isType('string'), 'admin')
            ->willReturn(LoginAttemptStatus::Allowed);
        $attempts->expects($this->never())->method('recordFailure');
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(TwoFactorAuthenticationResetter::class, $resetter);
        $this->app->instance(LoginAttemptGuard::class, $attempts);

        $this->post(
            '/index.php?module=API&method=TwoFactorAuth.resetTwoFactorAuth'.
            '&userLogin=alice&format=json&token_auth=super-token',
            ['passwordConfirmation' => 'correct'],
        )->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_missing_or_incorrect_confirmation_does_not_reset_two_factor_auth(): void
    {
        $this->authenticateSuperuser();
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->once())->method('isCorrect')->with('admin', 'wrong')->willReturn(false);
        $resetter = $this->createMock(TwoFactorAuthenticationResetter::class);
        $resetter->expects($this->never())->method('reset');
        $attempts = $this->createMock(LoginAttemptGuard::class);
        $attempts->expects($this->once())->method('status')->with($this->isType('string'), 'admin')
            ->willReturn(LoginAttemptStatus::Allowed);
        $attempts->expects($this->once())->method('recordFailure')->with($this->isType('string'), 'admin');
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(TwoFactorAuthenticationResetter::class, $resetter);
        $this->app->instance(LoginAttemptGuard::class, $attempts);

        $this->post(
            '/index.php?module=API&method=TwoFactorAuth.resetTwoFactorAuth'.
            '&userLogin=alice&format=json&token_auth=super-token',
        )->assertStatus(400)->assertJsonPath(
            'message',
            'Please re-authenticate to confirm this change.',
        );
        $this->post(
            '/index.php?module=API&method=TwoFactorAuth.resetTwoFactorAuth'.
            '&userLogin=alice&format=json&token_auth=super-token',
            ['passwordConfirmation' => 'wrong'],
        )->assertStatus(400)->assertJsonPath(
            'message',
            'The current password you entered is not correct.',
        );
    }

    public function test_extension_can_disable_password_confirmation(): void
    {
        $this->authenticateSuperuser();
        Event::listen(
            PasswordConfirmationRequirementChecking::class,
            static function (PasswordConfirmationRequirementChecking $event): void {
                $event->required = false;
            },
        );
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->never())->method('isCorrect');
        $resetter = $this->createMock(TwoFactorAuthenticationResetter::class);
        $resetter->expects($this->once())->method('reset')->with('alice');
        $attempts = $this->createMock(LoginAttemptGuard::class);
        $attempts->expects($this->never())->method('status');
        $attempts->expects($this->never())->method('recordFailure');
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(TwoFactorAuthenticationResetter::class, $resetter);
        $this->app->instance(LoginAttemptGuard::class, $attempts);

        $this->post(
            '/index.php?module=API&method=TwoFactorAuth.resetTwoFactorAuth'.
            '&userLogin=alice&format=json&token_auth=super-token',
        )->assertOk();
    }

    public function test_non_superuser_and_missing_target_are_rejected(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->post(
            '/index.php?module=API&method=TwoFactorAuth.resetTwoFactorAuth'.
            '&userLogin=alice&format=json',
        )->assertStatus(401)->assertJsonPath(
            'message',
            "You can't access this resource as it requires a 'superuser' access.",
        );
        $this->post(
            '/index.php?module=API&method=TwoFactorAuth.resetTwoFactorAuth&format=json',
        )->assertStatus(400)->assertJsonPath('message', "Please specify a value for 'userLogin'.");
    }

    public function test_brute_force_block_prevents_password_verification_and_reset(): void
    {
        $this->authenticateSuperuser();
        $attempts = $this->createStub(LoginAttemptGuard::class);
        $attempts->method('status')->willReturn(LoginAttemptStatus::IpBlocked);
        $passwords = $this->createMock(PasswordConfirmationVerifier::class);
        $passwords->expects($this->never())->method('isCorrect');
        $resetter = $this->createMock(TwoFactorAuthenticationResetter::class);
        $resetter->expects($this->never())->method('reset');
        $this->app->instance(LoginAttemptGuard::class, $attempts);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);
        $this->app->instance(TwoFactorAuthenticationResetter::class, $resetter);

        $this->post(
            '/index.php?module=API&method=TwoFactorAuth.resetTwoFactorAuth'.
            '&userLogin=alice&format=json&token_auth=super-token',
            ['passwordConfirmation' => 'correct'],
        )->assertStatus(403)->assertJsonPath(
            'message',
            'Too many failed logins. Please wait and try logging in again later.',
        );
    }

    private function authenticateSuperuser(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('authenticatedLogin')->willReturn('admin');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
