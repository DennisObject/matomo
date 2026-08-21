<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Login\UiSessionFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

final class UiSessionFingerprintTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_remembered_sessions_last_the_configured_lifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00:00', 'UTC'));
        $sessions = new UiSessionFingerprint(sessionLifetime: 120, idleTimeout: 30);
        $session = $this->session();

        $sessions->initialize($session, 'alice', true);

        $this->assertSame('alice', $session->get('matomo.login'));
        $this->assertSame('alice', $session->get('user.name'));
        $this->assertTrue($sessions->remembered($session));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00:31', 'UTC'));
        $this->assertTrue($sessions->maintain($session));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:02:32', 'UTC'));
        $this->assertFalse($sessions->maintain($session));
    }

    public function test_idle_sessions_expire_after_the_idle_timeout(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00:00', 'UTC'));
        $sessions = new UiSessionFingerprint(sessionLifetime: 120, idleTimeout: 30);
        $session = $this->session();

        $sessions->initialize($session, 'alice', false);
        $this->assertFalse($sessions->remembered($session));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-21 12:00:31', 'UTC'));
        $this->assertFalse($sessions->maintain($session));
    }

    public function test_remember_me_accepts_matomo_checkbox_values(): void
    {
        $this->assertTrue(UiSessionFingerprint::wantsRememberMe('1'));
        $this->assertTrue(UiSessionFingerprint::wantsRememberMe(1));
        $this->assertFalse(UiSessionFingerprint::wantsRememberMe('0'));
        $this->assertFalse(UiSessionFingerprint::wantsRememberMe(0));
        $this->assertFalse(UiSessionFingerprint::wantsRememberMe(null));
    }

    private function session(): Store
    {
        return new Store('test', new ArraySessionHandler(120));
    }
}
