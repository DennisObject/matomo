<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use App\Matomo\TwoFactorAuth\TwoFactorCodeVerifier;
use App\Matomo\TwoFactorAuth\TwoFactorUser;
use App\Matomo\Users\UserIdentityRepository;
use Tests\TestCase;

final class TwoFactorAuthControllerTest extends TestCase
{
    public function test_accepts_a_valid_authentication_code(): void
    {
        $this->enableTwoFactor('alice', true);
        $this->signIn('alice');

        $this->get('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth')
            ->assertOk()
            ->assertSee('Authentication code')
            ->assertSee('name="form_authcode"', false);

        $this->post('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth', [
            'form_authcode' => '123456',
            'form_nonce' => session()->token(),
        ])->assertRedirect('/index.php?module=CoreHome&action=index');

        $this->get('/index.php?module=CoreHome&action=index')
            ->assertOk()
            ->assertSee('Signed in as alice');
        $this->assertSame(1, session('twofactorauth.verified'));
        $this->assertSame('alice', session('twofactorauth.verified_user'));
    }

    public function test_rejects_an_invalid_authentication_code(): void
    {
        $this->enableTwoFactor('alice', false);
        $this->signIn('alice');

        $this->get('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth')->assertOk();
        $this->post('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth', [
            'form_authcode' => '000000',
            'form_nonce' => session()->token(),
        ])
            ->assertForbidden()
            ->assertSee('Incorrect two-factor authentication code.');
        $this->get('/index.php?module=CoreHome')
            ->assertRedirect('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth');
    }

    private function enableTwoFactor(string $login, bool $codeMatches): void
    {
        $this->app->instance(TwoFactorUser::class, new readonly class($login) implements TwoFactorUser
        {
            public function __construct(private string $enabledLogin) {}

            public function isEnabled(string $login): bool
            {
                return $login === $this->enabledLogin;
            }
        });
        $this->app->instance(TwoFactorCodeVerifier::class, new readonly class($codeMatches) implements TwoFactorCodeVerifier
        {
            public function __construct(private bool $codeMatches) {}

            public function verify(string $login, string $code): bool
            {
                return $this->codeMatches && $code === '123456';
            }
        });
    }

    private function signIn(string $login): void
    {
        $identities = $this->createStub(UserIdentityRepository::class);
        $identities->method('loginForEmail')->willReturn($login);
        $this->app->instance(UserIdentityRepository::class, $identities);

        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn(true);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);

        $attempts = $this->createStub(LoginAttemptGuard::class);
        $attempts->method('status')->willReturn(LoginAttemptStatus::Allowed);
        $this->app->instance(LoginAttemptGuard::class, $attempts);

        $this->get('/index.php')->assertOk();
        $this->post('/index.php?module=Login', [
            'form_login' => $login,
            'form_password' => 'secret',
            'form_nonce' => session()->token(),
        ])->assertRedirect('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth');
    }
}
