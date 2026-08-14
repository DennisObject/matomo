<?php

declare(strict_types=1);

namespace App\Matomo\Security;

use Illuminate\Http\Request;
use Matomo\Network\IP;
use Matomo\Network\IPUtils;

final readonly class ClientIpResolver
{
    /**
     * @param  list<string>  $proxyHeaders
     * @param  list<string>  $proxyIps
     */
    public function __construct(
        private array $proxyHeaders,
        private array $proxyIps,
        private bool $readLastProxyIp,
    ) {}

    public function resolve(Request $request): string
    {
        $remoteAddress = $request->server('REMOTE_ADDR', '0.0.0.0');
        $default = is_string($remoteAddress) ? $remoteAddress : '0.0.0.0';
        $excludedIps = $this->proxyIps;

        if (! $this->readLastProxyIp) {
            $excludedIps[] = $default;
        }

        foreach ($this->proxyHeaders as $proxyHeader) {
            $header = $request->server($proxyHeader);

            if (! is_string($header) || $header === '') {
                continue;
            }

            $clientIp = $this->ipFromList($header, $excludedIps);

            if ($clientIp !== '' && stripos($clientIp, 'unknown') === false) {
                return (string) IPUtils::sanitizeIp($clientIp);
            }
        }

        return (string) IPUtils::sanitizeIp($default);
    }

    /**
     * @param  list<string>  $excludedIps
     */
    private function ipFromList(string $header, array $excludedIps): string
    {
        if (! str_contains($header, ',')) {
            return trim(strip_tags($header));
        }

        $ips = [];

        foreach (explode(',', $header) as $value) {
            $ip = trim(strip_tags($value));

            if ($ip === '' || in_array($ip, $excludedIps, true)) {
                continue;
            }

            if (! IP::fromStringIP((string) IPUtils::sanitizeIp($ip))->isInRanges($excludedIps)) {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            return '';
        }

        return $this->readLastProxyIp ? $ips[array_key_last($ips)] : $ips[0];
    }
}
