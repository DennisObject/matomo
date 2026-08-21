<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\TwoFactorAuth\TimeBasedOneTimePassword;
use PHPUnit\Framework\TestCase;

final class TimeBasedOneTimePasswordTest extends TestCase
{
    public function test_accepts_the_current_window_and_rejects_stale_codes(): void
    {
        $passwords = new TimeBasedOneTimePassword;
        $secret = 'JBSWY3DPEHPK3PXP';
        $now = 1_700_000_000;
        $code = $passwords->codeAt($secret, intdiv($now, 30));

        $this->assertTrue($passwords->matches($secret, $code, 2, $now));
        $this->assertTrue($passwords->matches($secret, $code, 2, $now + 30));
        $this->assertFalse($passwords->matches($secret, $code, 2, $now + 180));
        $this->assertFalse($passwords->matches($secret, '000000', 2, $now));
    }
}
