<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Security\ClientIpResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ClientIpResolverTest extends TestCase
{
    public function test_uses_the_first_non_proxy_ip_from_a_configured_header(): void
    {
        $resolver = new ClientIpResolver(
            proxyHeaders: ['HTTP_X_FORWARDED_FOR'],
            proxyIps: ['10.0.0.0/8'],
            readLastProxyIp: false,
        );
        $request = Request::create('/', server: [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.0.0.1',
        ]);

        $this->assertSame('198.51.100.20', $resolver->resolve($request));
    }

    public function test_can_use_the_last_non_proxy_ip_from_a_configured_header(): void
    {
        $resolver = new ClientIpResolver(
            proxyHeaders: ['HTTP_X_FORWARDED_FOR'],
            proxyIps: ['10.0.0.0/8'],
            readLastProxyIp: true,
        );
        $request = Request::create('/', server: [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 203.0.113.30, 10.0.0.1',
        ]);

        $this->assertSame('203.0.113.30', $resolver->resolve($request));
    }
}
