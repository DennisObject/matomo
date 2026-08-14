<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Localization\MatomoTranslator;
use DateTimeZone;
use Exception;

final readonly class LocalizedTimezoneProvider implements TimezoneProvider
{
    private const array UTC_OFFSETS = [
        -12, -11.5, -11, -10.5, -10, -9.5, -9, -8.5, -8, -7.5, -7, -6.5, -6, -5.5, -5,
        -4.5, -4, -3.5, -3, -2.5, -2, -1.5, -1, -0.5, 0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5, 4,
        4.5, 5, 5.5, 5.75, 6, 6.5, 7, 7.5, 8, 8.5, 8.75, 9, 9.5, 10, 10.5, 11, 11.5, 12,
        12.75, 13, 13.75, 14,
    ];

    /**
     * @param  array<string, string>  $countries
     */
    public function __construct(
        private MatomoTranslator $translator,
        private array $countries,
    ) {}

    public function all(string $language, bool $timezoneSupportEnabled): array
    {
        if (! $timezoneSupportEnabled) {
            return ['UTC' => $this->utcOffsets($language)];
        }

        $groups = [];
        $continents = [];

        foreach ($this->countries as $countryCode => $continentCode) {
            $countryCode = strtoupper($countryCode);
            $countryTimezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);

            foreach ($countryTimezones as $timezone) {
                $continents[$continentCode] ??= $this->translator->translate(
                    "Intl_Continent_{$continentCode}",
                    $language,
                );
                $continent = $continents[$continentCode];
                $groups[$continent][$timezone] = $this->name(
                    $timezone,
                    $language,
                    $countryCode,
                    count($countryTimezones) > 1,
                );
            }
        }

        ksort($groups);

        foreach ($groups as &$timezones) {
            asort($timezones);
        }

        unset($timezones);
        $groups['UTC'] = $this->utcOffsets($language);

        return $groups;
    }

    public function name(
        string $timezone,
        string $language,
        ?string $countryCode = null,
        ?bool $multipleTimezonesInCountry = null,
    ): string {
        if (str_starts_with($timezone, 'UTC')) {
            $offset = str_replace(
                ['.25', '.5', '.75'],
                [':15', ':30', ':45'],
                substr($timezone, 3),
            );

            return $this->translator->translate('SitesManager_Format_Utc', $language, [$offset]);
        }

        if ($countryCode === null) {
            try {
                $location = (new DateTimeZone($timezone))->getLocation();
                $detectedCountryCode = $location['country_code'] ?? null;

                if (is_string($detectedCountryCode) && $detectedCountryCode !== '??') {
                    $countryCode = $detectedCountryCode;
                }
            } catch (Exception) {
                // Keep the legacy city-name fallback for unknown timezones.
            }
        }

        if (empty($countryCode)) {
            return $this->city($timezone);
        }

        $multipleTimezonesInCountry ??= count(
            DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode),
        ) > 1;
        $name = $this->translator->translate("Intl_Country_{$countryCode}", $language);

        if (! $multipleTimezonesInCountry) {
            return $name;
        }

        $translationKey = 'Intl_Timezone_'.str_replace(['_', '/'], ['', '_'], $timezone);
        $city = $this->translator->translate($translationKey, $language);

        if ($city === $translationKey) {
            $city = $this->city($timezone);
        }

        return $name.' - '.$city;
    }

    private function city(string $timezone): string
    {
        $parts = explode('/', $timezone);

        return str_replace('_', ' ', end($parts));
    }

    /**
     * @return array<string, string>
     */
    private function utcOffsets(string $language): array
    {
        $timezones = [];

        foreach (self::UTC_OFFSETS as $offset) {
            $formattedOffset = rtrim(rtrim(number_format($offset, 2, '.', ''), '0'), '.');

            if ($offset > 0) {
                $formattedOffset = '+'.$formattedOffset;
            } elseif ($offset === 0) {
                $formattedOffset = '';
            }

            $timezone = 'UTC'.$formattedOffset;
            $timezones[$timezone] = $this->name($timezone, $language);
        }

        return $timezones;
    }
}
