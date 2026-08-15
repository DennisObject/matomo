<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class BrowserLanguageArchiveLabeler
{
    /**
     * @param  list<string>  $languageCodes
     * @param  list<string>  $countryCodes
     * @param  array<string, string>  $countriesByLanguage
     */
    public function __construct(
        private array $languageCodes,
        private array $countryCodes,
        private array $countriesByLanguage,
    ) {}

    public function label(string $browserLanguage): string
    {
        $browserLanguage = strtolower(str_replace('_', '-', $browserLanguage));
        $language = $this->language($browserLanguage);
        $country = $this->country($browserLanguage);

        return $country === 'xx' || $country === $language
            ? $language
            : $language.'-'.$country;
    }

    private function language(string $browserLanguage): string
    {
        $matchCount = preg_match_all(
            '/(?:^|,)([a-z]{2,3})(?:-[a-z]{4})?(?:-[a-z]{2})?/',
            $browserLanguage,
            $matches,
            PREG_SET_ORDER,
        );

        if (! is_int($matchCount) || $matchCount < 1) {
            return 'xx';
        }

        foreach ($matches as $match) {
            $language = $match[1];

            if (in_array($language, $this->languageCodes, true)) {
                return $language;
            }
        }

        return 'xx';
    }

    private function country(string $browserLanguage): string
    {
        if (preg_match('/^([a-z]{2,3})(?:,|;|$)/D', $browserLanguage, $match) === 1
            && isset($this->countriesByLanguage[$match[1]])) {
            return $this->countriesByLanguage[$match[1]];
        }

        $matchCount = preg_match_all('/-([a-z]{2})/', $browserLanguage, $matches, PREG_SET_ORDER);

        if (! is_int($matchCount) || $matchCount < 1) {
            return 'xx';
        }

        foreach ($matches as $match) {
            if (in_array($match[1], $this->countryCodes, true)) {
                return $match[1];
            }
        }

        return 'xx';
    }
}
