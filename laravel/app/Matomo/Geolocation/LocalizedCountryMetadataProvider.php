<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use App\Matomo\Localization\MatomoTranslator;

final readonly class LocalizedCountryMetadataProvider implements CountryMetadataProvider
{
    /**
     * @param  array<string, string>  $continentsByCountry
     * @param  array<string, array<array-key, array{name?: mixed, altNames?: mixed}>>  $isoRegions
     * @param  array<string, array<array-key, string>>  $legacyRegions
     * @param  array<string, array<array-key, string>>  $legacyRegionMapping
     */
    public function __construct(
        private array $continentsByCountry,
        private array $isoRegions,
        private array $legacyRegions,
        private array $legacyRegionMapping,
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
        $continentCode = strtolower($continentCode);

        if ($continentCode === '' || $continentCode === 'unk') {
            return $this->translator->translate('General_Unknown', $language);
        }

        return $this->translator->translate('Intl_Continent_'.$continentCode, $language);
    }

    public function flag(string $countryCode): string
    {
        $countryCode = $this->normalizeCountryCode($countryCode);

        if (preg_match('/^[a-z]{2}$/D', $countryCode) !== 1) {
            return 'plugins/Morpheus/icons/dist/flags/xx.png';
        }

        $relativePath = "plugins/Morpheus/icons/dist/flags/{$countryCode}.png";

        return is_file($this->flagDirectory."/{$countryCode}.png")
            ? $relativePath
            : 'plugins/Morpheus/icons/dist/flags/xx.png';
    }

    public function regionName(string $countryCode, string $regionCode, string $language): string
    {
        $countryCode = strtoupper($this->normalizeCountryCode($countryCode));
        $regionCode = strtoupper($regionCode);
        $isoName = $this->isoRegions[$countryCode][$regionCode]['name'] ?? null;

        if (is_string($isoName)) {
            return $isoName;
        }

        $legacyName = $this->legacyRegions[$countryCode][$regionCode] ?? null;

        return is_string($legacyName)
            ? $legacyName
            : $this->translator->translate('General_Unknown', $language);
    }

    public function regionCodeForName(string $countryCode, string $regionName): string
    {
        foreach ($this->isoRegions[strtoupper($countryCode)] ?? [] as $code => $region) {
            $names = [$region['name'] ?? null];

            if (is_array($region['altNames'] ?? null)) {
                $names = [...$names, ...$region['altNames']];
            }

            foreach ($names as $name) {
                if (is_string($name) && $this->normalizedName($name) === $this->normalizedName($regionName)) {
                    return (string) $code;
                }
            }
        }

        return '';
    }

    public function convertLegacyRegion(string $countryCode, string $regionCode): array
    {
        $countryCode = strtoupper($countryCode);
        $regionCode = strtoupper($regionCode);

        if ($countryCode === '' || in_array($countryCode, ['EU', 'AP', 'A1', 'A2'], true)) {
            return ['country' => '', 'region' => ''];
        }

        if (in_array($countryCode, ['US', 'CA'], true)) {
            return ['country' => strtolower($countryCode), 'region' => $regionCode];
        }

        if ($countryCode === 'TI') {
            return ['country' => 'cn', 'region' => '14'];
        }

        return [
            'country' => strtolower($countryCode),
            'region' => $this->legacyRegionMapping[$countryCode][$regionCode] ?? $regionCode,
        ];
    }

    private function normalizeCountryCode(string $countryCode): string
    {
        $countryCode = strtolower($countryCode);

        return $countryCode === 'ti' ? 'cn' : $countryCode;
    }

    private function normalizedName(string $name): string
    {
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);

            if (is_string($transliterated)) {
                $name = $transliterated;
            }
        } elseif (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT', $name);

            if (is_string($transliterated)) {
                $name = $transliterated;
            }
        }

        return strtolower($name);
    }
}
