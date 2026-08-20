<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class TrackerLocation
{
    public function __construct(
        public string $country = 'xx',
        public ?string $region = null,
        public ?string $city = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}

    /** @param array<string, float|int|string|null> $location */
    public static function fromProviderResult(?array $location): self
    {
        if ($location === null) {
            return new self;
        }

        $country = strtolower(trim((string) ($location['country_code'] ?? 'xx')));
        $region = $location['region_code'] ?? $location['region'] ?? null;
        $city = $location['city_name'] ?? $location['city'] ?? null;
        $latitude = is_numeric($location['lat'] ?? null) ? (float) $location['lat'] : null;
        $longitude = is_numeric($location['long'] ?? $location['lng'] ?? null)
            ? (float) ($location['long'] ?? $location['lng'])
            : null;

        return new self(
            country: preg_match('/^[a-z]{2,3}$/D', $country) === 1 ? $country : 'xx',
            region: is_string($region) && $region !== '' ? mb_substr($region, 0, 100) : null,
            city: is_string($city) && $city !== '' ? mb_substr($city, 0, 255) : null,
            latitude: $latitude,
            longitude: $longitude,
        );
    }

    /** @return array<string, float|string|null> */
    public function visitColumns(): array
    {
        return [
            'location_country' => $this->country,
            'location_region' => $this->region,
            'location_city' => $this->city,
            'location_latitude' => $this->latitude,
            'location_longitude' => $this->longitude,
        ];
    }
}
