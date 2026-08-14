<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

final readonly class LocationResultCompleter
{
    public function __construct(private CountryMetadataProvider $countries) {}

    /**
     * @param  array<string, float|int|string|null>  $location
     * @return array<string, float|int|string|null>
     */
    public function complete(array $location, string $language): array
    {
        $countryCode = $this->string($location['country_code'] ?? null);
        $continentCode = $this->string($location['continent_code'] ?? null);

        if ($continentCode === '' && $countryCode !== '') {
            $continentCode = $this->countries->continentCode($countryCode);
            $location['continent_code'] = $continentCode;
        }

        if ($this->string($location['continent_name'] ?? null) === '' && $continentCode !== '') {
            $location['continent_name'] = $this->countries->continentName($continentCode, $language);
        }

        if ($this->string($location['country_name'] ?? null) === '' && $countryCode !== '') {
            $location['country_name'] = $this->countries->countryName($countryCode, $language);
        }

        foreach (['lat', 'long'] as $coordinate) {
            $value = $location[$coordinate] ?? null;

            if (in_array($value, [null, '', 0, 0.0, '0'], true)) {
                continue;
            }

            if (! is_numeric($value)) {
                unset($location[$coordinate]);

                continue;
            }

            $location[$coordinate] = round((float) $value, 2);
        }

        return $location;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
