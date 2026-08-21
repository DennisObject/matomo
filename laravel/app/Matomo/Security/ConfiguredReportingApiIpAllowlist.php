<?php

declare(strict_types=1);

namespace App\Matomo\Security;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Matomo\Network\IP;
use Matomo\Network\IPUtils;

final readonly class ConfiguredReportingApiIpAllowlist implements ReportingApiIpAllowlist
{
    /**
     * @param  list<string>  $allowlistedIps
     */
    public function __construct(
        private ClientIpResolver $clientIps,
        private Repository $cache,
        private array $allowlistedIps,
        private bool $appliesToReportingApi,
    ) {}

    public function deniedClientIp(Request $request): ?string
    {
        if (! $this->appliesToReportingApi) {
            return null;
        }

        return $this->deniedUiClientIp($request);
    }

    public function deniedUiClientIp(Request $request): ?string
    {
        if ($this->allowlistedIps === []) {
            return null;
        }

        $clientIp = $this->clientIps->resolve($request);

        return IP::fromStringIP($clientIp)->isInRanges($this->resolvedAllowlist())
            ? null
            : $clientIp;
    }

    /**
     * @return list<string>
     */
    private function resolvedAllowlist(): array
    {
        $resolved = [];

        foreach ($this->allowlistedIps as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            if (filter_var($value, FILTER_VALIDATE_IP) !== false || IPUtils::getIPRangeBounds($value) !== null) {
                $resolved[] = $value;

                continue;
            }

            $resolved = [...$resolved, ...$this->resolveHostname($value)];
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @return list<string>
     */
    private function resolveHostname(string $hostname): array
    {
        $cached = $this->cache->remember(
            'matomo.login-allowlist.dns.'.md5($hostname),
            30,
            static function () use ($hostname): array {
                $addresses = [];
                $ipv4 = @gethostbyname($hostname);

                if ($ipv4 !== $hostname && filter_var($ipv4, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $ipv4;
                }

                if (function_exists('dns_get_record')) {
                    $records = @dns_get_record($hostname, DNS_AAAA);

                    if (is_array($records)) {
                        foreach ($records as $record) {
                            $ipv6 = $record['ipv6'] ?? null;

                            if (is_string($ipv6) && filter_var($ipv6, FILTER_VALIDATE_IP) !== false) {
                                $addresses[] = $ipv6;
                            }
                        }
                    }
                }

                return array_values(array_unique($addresses));
            },
        );

        return $cached;
    }
}
