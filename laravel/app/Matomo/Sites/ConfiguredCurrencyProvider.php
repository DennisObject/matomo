<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

final readonly class ConfiguredCurrencyProvider implements CurrencyProvider
{
    /**
     * @param  array<array-key, mixed>  $currencies
     * @param  array<string, string>  $customCurrencies
     */
    public function __construct(
        private array $currencies,
        private array $customCurrencies,
    ) {}

    public function symbols(): array
    {
        $currencies = $this->currencies;

        foreach ($this->customCurrencies as $code => $name) {
            $currencies[$code] = [$code, $name];
        }

        $symbols = [];

        foreach ($currencies as $code => $currency) {
            if (! is_string($code) || ! is_array($currency) || ! is_string($currency[0] ?? null)) {
                continue;
            }

            $symbols[$code] = $currency[0];
        }

        return $symbols;
    }
}
