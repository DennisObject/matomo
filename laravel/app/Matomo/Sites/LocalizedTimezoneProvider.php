<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Localization\MatomoTranslator;
use DateTimeZone;
use Exception;

final readonly class LocalizedTimezoneProvider implements TimezoneProvider
{
    public function __construct(private MatomoTranslator $translator) {}

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
}
