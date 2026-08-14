<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Localization\MatomoTranslator;

final readonly class LocalizedSiteDetailsPresenter implements SiteDetailsPresenter
{
    public function __construct(
        private TimezoneProvider $timezones,
        private MatomoTranslator $translator,
    ) {}

    public function present(array $site, string $language, bool $includeCreator): array
    {
        $timezone = $site['timezone'] ?? null;

        if (is_string($timezone)) {
            $site['timezone_name'] = $this->timezones->name($timezone, $language);
        }

        $currency = $site['currency'] ?? null;

        if (is_string($currency)) {
            $translationKey = "Intl_Currency_{$currency}";
            $currencyName = $this->translator->translate($translationKey, $language);
            $site['currency_name'] = $currencyName === $translationKey ? $currency : $currencyName;
        }

        if (! $includeCreator) {
            unset($site['creator_login']);
        }

        return $site;
    }
}
