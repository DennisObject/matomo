<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Security\BlockedEgressTarget;
use App\Matomo\Security\EgressHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EgressHostResolverTest extends TestCase
{
    #[DataProvider('publicIpCases')]
    public function test_public_ip_classification(string $ip, bool $expected): void
    {
        $this->assertSame($expected, EgressHostResolver::isPublicIp($ip));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function publicIpCases(): iterable
    {
        yield 'public IPv4' => ['93.184.216.34', true];
        yield 'public IPv6' => ['2606:2800:220:1:248:1893:25c8:1946', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'cloud metadata' => ['169.254.169.254', false];
        yield 'carrier NAT' => ['100.64.0.1', false];
        yield 'documentation' => ['203.0.113.1', false];
        yield 'IPv4 mapped IPv6' => ['::ffff:5db8:d822', false];
        yield 'IPv6 loopback' => ['::1', false];
        yield 'IPv6 multicast' => ['ff02::1', false];
    }

    public function test_resolves_and_normalizes_a_public_dns_host(): void
    {
        $resolver = new EgressHostResolver(
            resolver: static fn (string $host): array => $host === 'example.com'
                ? ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']
                : [],
        );

        $this->assertSame(
            ['example.com', '93.184.216.34'],
            $resolver->resolveTarget('ExAmple.COM.'),
        );
    }

    public function test_rejects_a_dns_host_when_any_answer_is_private(): void
    {
        $resolver = new EgressHostResolver(
            resolver: static fn (string $host): array => ['93.184.216.34', '10.0.0.5'],
        );

        $this->expectException(BlockedEgressTarget::class);
        $this->expectExceptionMessage('private or reserved address');

        $resolver->resolveTarget('example.com');
    }

    public function test_rejects_encoded_numeric_hosts(): void
    {
        $resolver = new EgressHostResolver(resolver: static fn (string $host): array => []);

        $this->expectException(BlockedEgressTarget::class);
        $this->expectExceptionMessage('numeric or encoded host');

        $resolver->resolveTarget('2130706433');
    }

    public function test_rejects_a_host_on_the_matomo_http_blocklist(): void
    {
        $resolver = new EgressHostResolver(
            resolver: static fn (string $host): array => ['52.216.50.10'],
        );

        $this->expectException(BlockedEgressTarget::class);
        $this->expectExceptionMessage('hostname bucket.amazonaws.com is blocked');

        $resolver->resolveTarget('BUCKET.AMAZONAWS.COM.');
    }

    public function test_accepts_an_explicitly_allowlisted_private_range(): void
    {
        $resolver = new EgressHostResolver(['10.0.0.0/8']);

        $this->assertSame(['10.1.2.3', '10.1.2.3'], $resolver->resolveTarget('10.1.2.3'));
    }
}
