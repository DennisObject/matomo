<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use App\Matomo\Localization\MatomoTranslator;

final readonly class LocalizedCountryMetadataProvider implements CountryMetadataProvider
{
    /**
     * @param  array<string, string>  $continentsByCountry
     */
    public function __construct(
        private array $continentsByCountry,
        private string $flagDirectory,
        private MatomoTranslator $translator,
    ) {}

    public function codes(): array
    {
        return array_keys($this->continentsByCountry);
    }

    public function continentCode(string $countryCode): string
    {
        $countryCode = $this->normalizeCountryCode($countryCode);

        return $this->continentsByCountry[$countryCode] ?? 'unk';
    }

    public function countryName(string $countryCode, string $language): string
    {
        $countryCode = $this->normalizeCountryCode($countryCode);

        if ($countryCode === '' || $countryCode === 'xx') {
            return $this->translator->translate('General_Unknown', $language);
        }

        foreach (['Intl_Country_'.strtoupper($countryCode), 'UserCountry_country_'.$countryCode] as $key) {
            $translation = $this->translator->translate($key, $language);

            if ($translation !== $key) {
                return $translation;
            }
        }

        return $this->translator->translate('General_Unknown', $language);
    }

    public function continentName(string $continentCode, string $language): string
    {
        if ($continentCode === '' || $continentCode === 'unk') {
            return $this->translator->translate('General_Unknown', $language);
        }

        return $this->translator->translate('Intl_Continent_'.$continentCode, $language);
    }

    public function flag(string $countryCode): string
    {
        $countryCode = $this->normalizeCountryCode($countryCode);
        $relativePath = "plugins/Morpheus/icons/dist/flags/{$countryCode}.png";

        return is_file($this->flagDirectory."/{$countryCode}.png")
            ? $relativePath
            : 'plugins/Morpheus/icons/dist/flags/xx.png';
    }

    private function normalizeCountryCode(string $countryCode): string
    {
        $countryCode = strtolower($countryCode);

        return $countryCode === 'ti' ? 'cn' : $countryCode;
    }
}
