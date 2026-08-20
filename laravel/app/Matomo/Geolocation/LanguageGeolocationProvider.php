<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

final readonly class LanguageGeolocationProvider implements GeolocationProvider
{
    /**
     * @param  list<string>  $countryCodes
     * @param  array<string, string>  $countriesByLanguage
     */
    public function __construct(
        private bool $enabled,
        private bool $guessCountryFromLanguage,
        private array $countryCodes,
        private array $countriesByLanguage,
    ) {}

    public function id(): string
    {
        return 'default';
    }

    public function available(): bool
    {
        return $this->enabled;
    }

    public function locate(string $ipAddress, string $browserLanguage, string $currentIpAddress): array
    {
        $language = strtolower(str_replace('_', '-', $browserLanguage));
        $country = 'xx';

        if ($this->guessCountryFromLanguage
            && preg_match('/^([a-z]{2,3})(?:,|;|$)/D', $language, $matches) === 1
            && isset($this->countriesByLanguage[$matches[1]])) {
            $country = $this->countriesByLanguage[$matches[1]];
        } elseif (preg_match_all('/[-]([a-z]{2})/', $language, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                if (in_array($match[1], $this->countryCodes, true)) {
                    $country = $match[1];

                    break;
                }
            }
        }

        return ['country_code' => $country];
    }

    public function activate(): void {}
}
