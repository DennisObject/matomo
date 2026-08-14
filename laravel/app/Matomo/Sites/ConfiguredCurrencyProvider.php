<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Localization\MatomoTranslator;

final readonly class ConfiguredCurrencyProvider implements CurrencyProvider
{
    /**
     * @param  array<array-key, mixed>  $currencies
     * @param  array<string, string>  $customCurrencies
     */
    public function __construct(
        private array $currencies,
        private array $customCurrencies,
        private MatomoTranslator $translator,
    ) {}

    public function symbols(): array
    {
        $symbols = [];

        foreach ($this->currencyData() as $code => $currency) {
            $symbols[$code] = $currency['symbol'];
        }

        return $symbols;
    }

    public function names(string $language): array
    {
        $names = [];

        foreach (array_keys($this->currencyData()) as $code) {
            $names[$code] = $this->translator->translate("Intl_Currency_{$code}", $language)
                .' ('.$this->translator->translate("Intl_CurrencySymbol_{$code}", $language).')';
        }

        asort($names);

        return $names;
    }

    /**
     * @return array<string, array{symbol: string, description: string}>
     */
    private function currencyData(): array
    {
        $currencies = $this->currencies;

        foreach ($this->customCurrencies as $code => $name) {
            $currencies[$code] = [$code, $name];
        }

        $data = [];

        foreach ($currencies as $code => $currency) {
            if (! is_string($code)
                || ! is_array($currency)
                || ! is_string($currency[0] ?? null)
                || ! is_string($currency[1] ?? null)) {
                continue;
            }

            $data[$code] = [
                'symbol' => $currency[0],
                'description' => $currency[1],
            ];
        }

        return $data;
    }
}
