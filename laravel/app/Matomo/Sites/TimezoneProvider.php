<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface TimezoneProvider
{
    /**
     * @return array<string, array<string, string>>
     */
    public function all(string $language, bool $timezoneSupportEnabled): array;

    public function name(
        string $timezone,
        string $language,
        ?string $countryCode = null,
        ?bool $multipleTimezonesInCountry = null,
    ): string;
}
