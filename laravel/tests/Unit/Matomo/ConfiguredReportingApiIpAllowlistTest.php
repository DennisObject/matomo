<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ConfiguredReportingApiIpAllowlist;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ConfiguredReportingApiIpAllowlistTest extends TestCase
{
    public function test_ui_allowlist_applies_even_when_reporting_api_is_exempt(): void
    {
        $allowlist = new ConfiguredReportingApiIpAllowlist(
            clientIps: new ClientIpResolver([], [], true),
            cache: new Repository(new ArrayStore),
            allowlistedIps: ['10.0.0.1'],
            appliesToReportingApi: false,
        );
        $request = Request::create('https://example.test/index.php', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $this->assertNull($allowlist->deniedClientIp($request));
        $this->assertSame('127.0.0.1', $allowlist->deniedUiClientIp($request));
    }

    public function test_empty_allowlist_denies_nobody(): void
    {
        $allowlist = new ConfiguredReportingApiIpAllowlist(
            clientIps: new ClientIpResolver([], [], true),
            cache: new Repository(new ArrayStore),
            allowlistedIps: [],
            appliesToReportingApi: true,
        );
        $request = Request::create('https://example.test/index.php', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $this->assertNull($allowlist->deniedClientIp($request));
        $this->assertNull($allowlist->deniedUiClientIp($request));
    }
}
