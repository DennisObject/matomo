<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface TimezoneProvider
{
    public function name(
        string $timezone,
        string $language,
        ?string $countryCode = null,
        ?bool $multipleTimezonesInCountry = null,
    ): string;
}
