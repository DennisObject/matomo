<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use App\Matomo\Options\MutableOptionRepository;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Matomo\Network\IP;
use Matomo\Network\IPv6;
use MaxMind\Db\Reader\InvalidDatabaseException;

final readonly class MaxMindDatabaseGeolocationProvider implements GeolocationProvider
{
    /** @var list<string> */
    private const array LOCATION_DATABASES = [
        'GeoIP2-City.mmdb',
        'DBIP-City.mmdb',
        'DBIP-City-Lite.mmdb',
        'DBIP-Country-Lite.mmdb',
        'DBIP-Country.mmdb',
        '@pattern:^dbip-city-lite-[0-9]{4}-[0-9]{2}\.mmdb$',
        'GeoIP2-City-Africa.mmdb',
        'GeoIP2-City-Asia-Pacific.mmdb',
        'GeoIP2-City-Europe.mmdb',
        'GeoIP2-City-North-America.mmdb',
        'GeoIP2-City-South-America.mmdb',
        'GeoIP2-Enterprise.mmdb',
        'GeoIP2-Country.mmdb',
        '@pattern:^dbip-country-lite-[0-9]{4}-[0-9]{2}\.mmdb$',
        'GeoLite2-City.mmdb',
        'GeoLite2-Country.mmdb',
        'DBIP-Enterprise.mmdb',
    ];

    /** @var list<string> */
    private const array ISP_DATABASES = [
        'GeoIP2-ISP.mmdb',
        'GeoLite2-ASN.mmdb',
        'DBIP-ISP.mmdb',
        'GeoIP2-Enterprise.mmdb',
        'DBIP-Enterprise.mmdb',
        'DBIP-ASN.mmdb',
        '@pattern:^dbip-asn-lite-[0-9]{4}-[0-9]{2}\.mmdb$',
    ];

    public function __construct(
        private string $databaseDirectory,
        private bool $ispEnabled,
        private MutableOptionRepository $options,
        private CountryMetadataProvider $countries,
    ) {}

    public function id(): string
    {
        return 'geoip2php';
    }

    public function available(): bool
    {
        return $this->databasePath(self::LOCATION_DATABASES) !== null
            || ($this->ispEnabled
                && $this->databasePath(self::ISP_DATABASES) !== null);
    }

    public function locate(string $ipAddress, string $browserLanguage, string $currentIpAddress): ?array
    {
        $ipAddress = $this->normalizedIp($ipAddress);

        if ($ipAddress === null) {
            return null;
        }

        $result = [];
        $locationPath = $this->databasePath(self::LOCATION_DATABASES);

        if ($locationPath !== null) {
            $result = $this->locationFromDatabase($locationPath, $ipAddress);
        }

        if ($this->ispEnabled) {
            $ispPath = $this->databasePath(self::ISP_DATABASES);

            if ($ispPath !== null) {
                $result = [...$result, ...$this->ispFromDatabase($ispPath, $ipAddress)];
            }
        }

        return $result === [] ? null : $result;
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

    /**
     * @param  list<string>  $names
     */
    private function databasePath(array $names): ?string
    {
        foreach ($names as $name) {
            if (str_starts_with($name, '@pattern:')) {
                $path = $this->matchingDatabasePath(substr($name, strlen('@pattern:')));

                if ($path !== null) {
                    return $path;
                }

                continue;
            }

            $path = $this->databaseDirectory.'/'.$name;

            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function matchingDatabasePath(string $pattern): ?string
    {
        $files = glob($this->databaseDirectory.'/*.mmdb');

        if (! is_array($files)) {
            return null;
        }

        rsort($files);

        foreach ($files as $path) {
            if (preg_match('/'.$pattern.'/Di', basename($path)) === 1 && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return array<string, float|int|string|null> */
    private function locationFromDatabase(string $path, string $ipAddress): array
    {
        try {
            $reader = new Reader($path);

            try {
                $type = $reader->metadata()->databaseType;
                $record = match ($type) {
                    'GeoLite2-Country',
                    'GeoIP2-Country',
                    'DBIP-Country-Lite',
                    'DBIP-Country',
                    'DBIP-Location (compat=Country)' => $reader->country($ipAddress),
                    'GeoLite2-City',
                    'DBIP-City-Lite',
                    'DBIP-City',
                    'GeoIP2-City',
                    'GeoIP2-City-Africa',
                    'GeoIP2-City-Asia-Pacific',
                    'GeoIP2-City-Europe',
                    'GeoIP2-City-North-America',
                    'GeoIP2-City-South-America',
                    'DBIP-Location (compat=City)' => $reader->city($ipAddress),
                    'GeoIP2-Enterprise',
                    'DBIP-Location-ISP (compat=Enterprise)',
                    'DBIP-Enterprise' => $reader->enterprise($ipAddress),
                    default => null,
                };

                return $record === null ? [] : $this->locationValues($record);
            } finally {
                $reader->close();
            }
        } catch (AddressNotFoundException|InvalidDatabaseException) {
            return [];
        }
    }

    /** @return array<string, float|int|string|null> */
    private function ispFromDatabase(string $path, string $ipAddress): array
    {
        try {
            $reader = new Reader($path);

            try {
                $type = $reader->metadata()->databaseType;
                $record = match ($type) {
                    'GeoIP2-ISP' => $reader->isp($ipAddress),
                    'GeoLite2-ASN', 'DBIP-ASN-Lite (compat=GeoLite2-ASN)' => $reader->asn($ipAddress),
                    'GeoIP2-Enterprise',
                    'DBIP-ISP (compat=Enterprise)',
                    'DBIP-Location-ISP (compat=Enterprise)',
                    'DBIP-ISP',
                    'DBIP-Enterprise' => $reader->enterprise($ipAddress),
                    default => null,
                };

                if ($record === null) {
                    return [];
                }

                $organization = $type === 'GeoLite2-ASN' || $type === 'DBIP-ASN-Lite (compat=GeoLite2-ASN)'
                    ? $this->stringProperty($record, 'autonomousSystemOrganization')
                    : $this->stringProperty($record, 'organization')
                        ?? $this->stringProperty($this->property($record, 'traits'), 'organization');
                $isp = $this->stringProperty($record, 'isp')
                    ?? $this->stringProperty($this->property($record, 'traits'), 'isp')
                    ?? $organization;

                return array_filter(
                    ['isp' => $isp, 'org' => $organization],
                    static fn (?string $value): bool => $value !== null && $value !== '',
                );
            } finally {
                $reader->close();
            }
        } catch (AddressNotFoundException|InvalidDatabaseException) {
            return [];
        }
    }

    /** @return array<string, float|int|string|null> */
    private function locationValues(object $record): array
    {
        $continent = $this->property($record, 'continent');
        $country = $this->property($record, 'country');
        $city = $this->property($record, 'city');
        $location = $this->property($record, 'location');
        $postal = $this->property($record, 'postal');
        $subdivisions = $this->property($record, 'subdivisions');
        $subdivision = is_array($subdivisions) && $subdivisions !== []
            ? ($this->stringProperty($country, 'isoCode') === 'GB' ? $subdivisions[array_key_last($subdivisions)] : $subdivisions[0])
            : null;
        $countryCode = strtoupper($this->stringProperty($country, 'isoCode') ?? '');
        $regionCode = strtoupper($this->stringProperty($subdivision, 'isoCode') ?? '');
        $regionName = $this->stringProperty($subdivision, 'name') ?? '';

        if ($countryCode !== '' && str_starts_with($regionCode, $countryCode.'-')) {
            $regionCode = substr($regionCode, strlen($countryCode) + 1);
        }

        if ($regionCode === '' && $regionName !== '') {
            $regionCode = $this->countries->regionCodeForName($countryCode, $regionName);
        }

        return $this->withoutEmpty([
            'continent_name' => $this->stringProperty($continent, 'name'),
            'continent_code' => strtoupper($this->stringProperty($continent, 'code') ?? ''),
            'country_code' => $countryCode,
            'country_name' => $this->stringProperty($country, 'name'),
            'city_name' => $this->stringProperty($city, 'name'),
            'lat' => $this->numberProperty($location, 'latitude'),
            'long' => $this->numberProperty($location, 'longitude'),
            'postal_code' => $this->stringProperty($postal, 'code'),
            'region_code' => $regionCode,
            'region_name' => $regionName,
        ]);
    }

    /**
     * @param  array<string, float|int|string|null>  $values
     * @return array<string, float|int|string|null>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter(
            $values,
            static fn (float|int|string|null $value): bool => ! in_array($value, [null, ''], true),
        );
    }

    private function property(mixed $object, string $property): mixed
    {
        if (! is_object($object)) {
            return null;
        }

        return $object->{$property} ?? null;
    }

    private function stringProperty(mixed $object, string $property): ?string
    {
        $value = $this->property($object, $property);

        return is_string($value) ? $value : null;
    }

    private function numberProperty(mixed $object, string $property): float|int|null
    {
        $value = $this->property($object, $property);

        return is_float($value) || is_int($value) ? $value : null;
    }

    private function normalizedIp(string $ipAddress): ?string
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $ip = IP::fromStringIP($ipAddress);

        return $ip instanceof IPv6 && $ip->isMappedIPv4()
            ? $ip->toIPv4String()
            : $ip->toString();
    }
}
