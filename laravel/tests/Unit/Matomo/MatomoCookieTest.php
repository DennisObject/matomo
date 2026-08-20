<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Tracker\MatomoCookie;
use PHPUnit\Framework\TestCase;

final class MatomoCookieTest extends TestCase
{
    public function test_encodes_and_reads_visitor_and_ignore_values(): void
    {
        $visitor = MatomoCookie::fromRaw(MatomoCookie::encode([0 => '0123456789abcdef']));
        $ignore = MatomoCookie::fromRaw(MatomoCookie::encode(['ignore' => '*']));

        $this->assertSame('0123456789abcdef', $visitor->visitorId());
        $this->assertTrue($ignore->ignoresVisits());
        $this->assertSame('0=MDEyMzQ1Njc4OWFiY2RlZg==', MatomoCookie::encode([0 => '0123456789abcdef']));
        $this->assertSame('ignore=Kg==', MatomoCookie::encode(['ignore' => '*']));
    }

    public function test_rejects_raw_star_and_invalid_payloads(): void
    {
        $this->assertFalse(MatomoCookie::fromRaw('*')->ignoresVisits());
        $this->assertNull(MatomoCookie::fromRaw('not-a-cookie')->visitorId());
        $this->assertNull(MatomoCookie::fromRaw(null)->visitorId());
        $this->assertNull(MatomoCookie::fromRaw(MatomoCookie::encode([0 => 'too-short']))->visitorId());
    }

    public function test_builds_https_none_and_http_lax_headers(): void
    {
        $https = MatomoCookie::header(
            '_pk_uid',
            '0=MDEyMzQ1Njc4OWFiY2RlZg==',
            1_700_000_000,
            '/',
            'www.example.test:443',
            true,
            'None',
        );
        $http = MatomoCookie::header(
            '_pk_uid',
            '0=MDEyMzQ1Njc4OWFiY2RlZg==',
            0,
            '',
            '',
            false,
            'Lax',
        );

        $this->assertStringContainsString('_pk_uid=0%3DMDEyMzQ1Njc4OWFiY2RlZg%3D%3D', $https);
        $this->assertStringContainsString('; path=/', $https);
        $this->assertStringContainsString('; domain=.example.test', $https);
        $this->assertStringContainsString('; secure', $https);
        $this->assertStringContainsString('; SameSite=None', $https);
        $this->assertStringContainsString('; SameSite=Lax', $http);
        $this->assertStringNotContainsString('; path=', $http);
        $this->assertStringNotContainsString('; domain=', $http);
        $this->assertStringNotContainsString('; secure', $http);
    }

    public function test_omits_samesite_none_for_safari_over_https(): void
    {
        $safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

        $this->assertSame('', MatomoCookie::sameSite('None', true, $safari));
        $this->assertSame('None', MatomoCookie::sameSite('None', true, $chrome));
        $this->assertSame('Lax', MatomoCookie::sameSite('None', false, $chrome));
    }
}
