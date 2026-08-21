<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use App\Matomo\Login\LogmeSettings;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ConfiguredReportingApiIpAllowlist;
use App\Matomo\Security\ReportingApiIpAllowlist;
use App\Matomo\TwoFactorAuth\TwoFactorUser;
use App\Matomo\Users\UserIdentityRepository;
use Illuminate\Contracts\Cache\Repository;
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
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) session('user.token_auth_temp'));
        $this->assertSame(0, session('twofactorauth.verified'));
    }

    public function test_redirects_to_a_trusted_same_host_url_after_login(): void
    {
        $this->bindLogin('alice', true);
        $this->get('/index.php')->assertOk();

        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'secret',
            'form_nonce' => session()->token(),
            'form_redirect' => 'http://localhost/index.php?module=SitesManager&action=index',
        ])->assertRedirect('/index.php?module=SitesManager&action=index');
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
        $this->assertNull(session('user.token_auth_temp'));
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

    public function test_rejects_ui_requests_from_an_ip_outside_the_allowlist(): void
    {
        $this->bindUiAllowlist(['10.0.0.1']);

        $this->get('/index.php')
            ->assertForbidden()
            ->assertSee('You cannot use this Matomo as your IP 127.0.0.1 is not allowed.');
    }

    public function test_allows_ui_requests_from_an_allowlisted_ip(): void
    {
        $this->bindUiAllowlist(['127.0.0.1']);

        $this->get('/index.php')->assertOk()->assertSee('Sign in');
    }

    public function test_skips_the_ui_allowlist_for_opt_out(): void
    {
        $this->bindUiAllowlist(['10.0.0.1']);

        $this->get('/index.php?module=CoreAdminHome&action=optOut')
            ->assertStatus(501);
    }

    public function test_logme_is_disabled_by_default(): void
    {
        $this->get('/index.php?module=Login&action=logme&login=alice&password=hash')
            ->assertForbidden()
            ->assertSee('This functionality has been disabled in config');
    }

    public function test_logme_signs_in_with_a_password_hash(): void
    {
        $this->app->instance(LogmeSettings::class, new LogmeSettings(true));
        $this->bindLogin('alice', true);

        $this->get('/index.php?module=Login&action=logme&login=alice&password=aabbccddeeff&url='
            .urlencode('http://localhost/index.php?module=SitesManager&action=index'))
            ->assertRedirect('/index.php?module=SitesManager&action=index');
        $this->get('/index.php?module=CoreHome')
            ->assertOk()
            ->assertSee('Signed in as alice');
    }

    public function test_logme_rejects_superusers(): void
    {
        $this->app->instance(LogmeSettings::class, new LogmeSettings(true));
        $this->bindLogin('admin', true, superUser: true);

        $this->get('/index.php?module=Login&action=logme&login=admin&password=aabbccddeeff')
            ->assertForbidden()
            ->assertSee("A user with superuser access cannot be authenticated using the 'logme' mechanism.");
    }

    public function test_password_login_requires_two_factor_when_it_is_enabled(): void
    {
        $this->bindLogin('alice', true);
        $this->app->instance(TwoFactorUser::class, new class implements TwoFactorUser
        {
            public function isEnabled(string $login): bool
            {
                return $login === 'alice';
            }
        });
        $this->get('/index.php')->assertOk();

        $this->post('/index.php?module=Login', [
            'form_login' => 'alice',
            'form_password' => 'secret',
            'form_nonce' => session()->token(),
        ])->assertRedirect('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth');

        $this->get('/index.php?module=CoreHome')
            ->assertRedirect('/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth');
        $this->assertSame(0, session('twofactorauth.verified'));
    }

    /** @param  list<string>  $ips */
    private function bindUiAllowlist(array $ips): void
    {
        $this->app->instance(ReportingApiIpAllowlist::class, new ConfiguredReportingApiIpAllowlist(
            clientIps: new ClientIpResolver([], [], true),
            cache: $this->app->make(Repository::class),
            allowlistedIps: $ips,
            appliesToReportingApi: false,
        ));
    }

    private function bindLogin(
        string $login,
        bool $passwordMatches,
        LoginAttemptStatus $status = LoginAttemptStatus::Allowed,
        bool $superUser = false,
    ): void {
        $identities = $this->createStub(UserIdentityRepository::class);
        $identities->method('loginForEmail')->willReturn($login);
        $identities->method('hasSuperUserAccess')->willReturn($superUser);
        $this->app->instance(UserIdentityRepository::class, $identities);

        $passwords = $this->createStub(PasswordConfirmationVerifier::class);
        $passwords->method('isCorrect')->willReturn($passwordMatches);
        $passwords->method('isCorrectHash')->willReturn($passwordMatches);
        $this->app->instance(PasswordConfirmationVerifier::class, $passwords);

        $attempts = $this->createStub(LoginAttemptGuard::class);
        $attempts->method('status')->willReturn($status);
        $this->app->instance(LoginAttemptGuard::class, $attempts);
    }
}
