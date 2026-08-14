<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

interface CountryMetadataProvider
{
    /** @return list<string> */
    public function codes(): array;

    public function continentCode(string $countryCode): string;

    public function countryName(string $countryCode, string $language): string;

    public function continentName(string $continentCode, string $language): string;

    public function flag(string $countryCode): string;
}
