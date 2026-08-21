<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Users\UserIdentityRepository;
use Tests\TestCase;

final class LoginControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ClientIpResolver::class, new ClientIpResolver([], [], true));
    }

    public function test_shows_the_login_form(): void
    {
        $this->get('/index.php')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('name="form_login"', false)
            ->assertSee('name="form_password"', false)
            ->assertSee('name="form_nonce"', false);
    }

    public function test_signs_in_with_a_correct_password(): void
    {
        $this->bindLogin('alice', true);

        $this->get('/index.php')->assertOk();
        $nonce = session()->token();

        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'secret',
            'form_nonce' => $nonce,
        ])->assertRedirect('/index.php?module=CoreHome&action=index');

        $this->get('/index.php?module=CoreHome&action=index')
            ->assertOk()
            ->assertSee('Signed in as alice');
    }

    public function test_rejects_an_incorrect_password(): void
    {
        $this->bindLogin('alice', false);

        $this->get('/index.php')->assertOk();
        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'wrong',
            'form_nonce' => session()->token(),
        ])
            ->assertForbidden()
            ->assertSee('The username and/or password you used are incorrect.');
    }

    public function test_rejects_a_blocked_login_attempt(): void
    {
        $this->bindLogin('alice', true, LoginAttemptStatus::IpBlocked);

        $this->get('/index.php')->assertOk();
        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'secret',
            'form_nonce' => session()->token(),
        ])
            ->assertForbidden()
            ->assertSee('this IP is blocked');
    }

    public function test_logs_out_and_returns_to_the_login_form(): void
    {
        $this->bindLogin('alice', true);
        $this->get('/index.php')->assertOk();
        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'secret',
            'form_nonce' => session()->token(),
        ])->assertRedirect();

        $this->get('/index.php?module=Login&action=logout')
            ->assertRedirect('/index.php?module=Login');
        $this->get('/index.php?module=CoreHome')
            ->assertRedirect('/index.php?module=Login');
    }

    public function test_remember_me_keeps_the_session_past_the_idle_timeout(): void
    {
        $this->bindLogin('alice', true);
        $this->get('/index.php')->assertOk();
        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'secret',
            'form_nonce' => session()->token(),
            'form_rememberme' => '1',
        ])->assertRedirect('/index.php?module=CoreHome&action=index');

        $this->assertTrue(session('session.info')['remembered']);
        $this->assertSame('alice', session('user.name'));
        $this->assertFalse((bool) config('session.expire_on_close'));

        $this->travel(3_601)->seconds();
        $this->get('/index.php?module=CoreHome&action=index')
            ->assertOk()
            ->assertSee('Signed in as alice');
    }

    public function test_idle_sessions_expire_when_remember_me_is_off(): void
    {
        $this->bindLogin('alice', true);
        $this->get('/index.php')->assertOk();
        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'secret',
            'form_nonce' => session()->token(),
        ])->assertRedirect();

        $this->assertFalse((bool) session('session.info')['remembered']);

        $this->travel(3_601)->seconds();
        $this->get('/index.php?module=CoreHome&action=index')
            ->assertRedirect('/index.php?module=Login');
    }

    private function bindLogin(
        string $login,
        bool $passwordMatches,
        LoginAttemptStatus $status = LoginAttemptStatus::Allowed,
    ): void {
        $identities = $this->createStub(UserIdentityRepository::class);
        $identities->method('loginForEmail')->willReturn($login);
        $this->app->instance(UserIdentityRepository::class, $identities);

        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn($passwordMatches);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);

        $attempts = $this->createStub(LoginAttemptGuard::class);
        $attempts->method('status')->willReturn($status);
        $this->app->instance(LoginAttemptGuard::class, $attempts);
    }
}
