<?php

declare(strict_types=1);

namespace App\Matomo\Security;

use Closure;
use Matomo\Network\IP;

final readonly class EgressHostResolver
{
    private const array DEFAULT_BLOCKED_HOSTS = ['*.amazonaws.com'];

    private const array EXTRA_BLOCKED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '::/96',
        '::ffff:0:0/96',
        '2002::/16',
        '2001::/32',
        '2001:db8::/32',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        'fe00::/8',
        'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param  list<string>  $allowedPrivateRanges
     * @param  (callable(string): list<string>)|null  $resolver
     * @param  list<string>  $blockedHosts
     */
    public function __construct(
        private array $allowedPrivateRanges = [],
        ?callable $resolver = null,
        private array $blockedHosts = self::DEFAULT_BLOCKED_HOSTS,
    ) {
        $this->resolver = $resolver === null
            ? self::resolveHostIpsViaDns(...)
            : $resolver(...);
    }

    /**
     * @return array{string, string} The canonical host and the IP address to pin.
     */
    public function resolveTarget(string $host): array
    {
        $host = trim($host, '[]');

        if ($host === '') {
            throw new BlockedEgressTarget('Refusing to fetch: empty host.');
        }

        $this->assertHostNotBlocked($host);

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            if (! function_exists('idn_to_ascii')) {
                throw new BlockedEgressTarget(
                    'Refusing to fetch: cannot normalise internationalised host without the intl extension.',
                );
            }

            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($ascii) || $ascii === '') {
                throw new BlockedEgressTarget('Refusing to fetch: host cannot be converted to ASCII.');
            }

            $host = $ascii;
        }

        $host = rtrim(strtolower($host), '.');

        if ($host === '') {
            throw new BlockedEgressTarget('Refusing to fetch: empty host.');
        }

        $this->assertHostNotBlocked($host);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isAllowedIp($host)) {
                throw new BlockedEgressTarget(
                    'Refusing to fetch: host resolves to a private or reserved address.',
                );
            }

            return [$host, $host];
        }

        if (preg_match('/^0x[0-9a-f]+$/i', $host) === 1 || preg_match('/^[0-9.]+$/', $host) === 1) {
            throw new BlockedEgressTarget('Refusing to fetch: numeric or encoded host is not allowed.');
        }

        if (preg_match(
            '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i',
            $host,
        ) !== 1) {
            throw new BlockedEgressTarget('Refusing to fetch: host is not a valid IP or DNS name.');
        }

        $ips = ($this->resolver)($host);

        if ($ips === []) {
            throw new BlockedEgressTarget('Refusing to fetch: host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! $this->isAllowedIp($ip)) {
                throw new BlockedEgressTarget(
                    'Refusing to fetch: host resolves to a private or reserved address.',
                );
            }
        }

        return [$host, $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        return ! IP::fromStringIP($ip)->isInRanges(self::EXTRA_BLOCKED_RANGES);
    }

    private function isAllowedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (self::isPublicIp($ip)) {
            return true;
        }

        return $this->allowedPrivateRanges !== []
            && IP::fromStringIP($ip)->isInRanges($this->allowedPrivateRanges);
    }

    private function wildcardHostPattern(string $host): string
    {
        $flexibleStart = str_starts_with($host, '*.');
        $flexibleEnd = str_ends_with($host, '.*');

        if ($flexibleStart) {
            $host = substr($host, 2);
        }

        if ($flexibleEnd) {
            $host = substr($host, 0, -2);
        }

        return '/^'.($flexibleStart ? '.*\.' : '').preg_quote($host, '/').
            ($flexibleEnd ? '\..*' : '').'$/i';
    }

    private function assertHostNotBlocked(string $host): void
    {
        foreach ($this->blockedHosts as $blockedHost) {
            if (preg_match($this->wildcardHostPattern($blockedHost), $host) === 1) {
                throw new BlockedEgressTarget("Refusing to fetch: hostname {$host} is blocked.");
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIpsViaDns(string $host): array
    {
        $ips = [];
        $ipv4 = @gethostbynamel($host);

        if (is_array($ipv4)) {
            $ips = $ipv4;
        }

        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $ipv6 = $record['ipv6'] ?? null;

                if (is_string($ipv6) && $ipv6 !== '') {
                    $ips[] = $ipv6;
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
