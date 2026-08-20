<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use App\Matomo\Options\MutableOptionRepository;
use Matomo\Network\IP;
use Matomo\Network\IPv6;

final readonly class ServerModuleGeolocationProvider implements GeolocationProvider
{
    public function __construct(
        private ServerVariableMapping $variables,
        private GeolocationProvider $fallback,
        private MutableOptionRepository $options,
    ) {}

    public function id(): string
    {
        return 'geoip2server';
    }

    public function available(): bool
    {
        $variables = $this->variables->variables();

        foreach (['continent_code', 'country_code', 'region_code', 'city_name'] as $resultName) {
            $serverVariable = $variables[$resultName] ?? null;

            if (! is_string($serverVariable)) {
                continue;
            }

            if (array_key_exists($serverVariable, $_SERVER)) {
                return true;
            }
        }

        return false;
    }

    public function locate(string $ipAddress, string $browserLanguage, string $currentIpAddress): ?array
    {
        $ipAddress = $this->normalizedIp($ipAddress);
        $currentIpAddress = $this->normalizedIp($currentIpAddress);

        if (! $this->sameOrAnonymizedIp($ipAddress, $currentIpAddress)) {
            return $this->fallback->available()
                ? $this->fallback->locate($ipAddress, $browserLanguage, $currentIpAddress)
                : null;
        }

        $location = [];

        foreach ($this->variables->variables() as $resultName => $serverVariable) {
            $value = $_SERVER[$serverVariable] ?? null;

            if ((is_float($value) || is_int($value) || is_string($value))
                && ! in_array($value, [0, 0.0, '0', ''], true)) {
                $location[$resultName] = $value;
            }
        }

        return $location === [] ? null : $location;
    }

    public function activate(): void
    {
        if (in_array(
            $this->options->value('usercountry.switchtoisoregions'),
            [null, '', '0'],
            true,
        )) {
            $this->options->set('usercountry.switchtoisoregions', (string) time());
        }
    }

    private function sameOrAnonymizedIp(string $ipAddress, string $currentIpAddress): bool
    {
        if ($ipAddress === $currentIpAddress) {
            return true;
        }

        $ipBytes = array_reverse(explode('.', $ipAddress));
        $currentBytes = array_reverse(explode('.', $currentIpAddress));

        if (count($ipBytes) !== 4 || count($currentBytes) !== 4) {
            return false;
        }

        foreach ($ipBytes as $index => $byte) {
            if ($byte === '0') {
                $currentBytes[$index] = '0';
            } else {
                break;
            }
        }

        return $ipBytes === $currentBytes;
    }

    private function normalizedIp(string $ipAddress): string
    {
        $ip = IP::fromStringIP($ipAddress);

        if ($ip instanceof IPv6 && $ip->isMappedIPv4()) {
            return $ip->toIPv4String() ?? $ip->toString();
        }

        return $ip->toString();
    }
}
