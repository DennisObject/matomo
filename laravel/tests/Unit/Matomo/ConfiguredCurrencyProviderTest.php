<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Sites\ConfiguredCurrencyProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredCurrencyProviderTest extends TestCase
{
    public function test_custom_currencies_use_their_code_as_the_symbol(): void
    {
        $translator = new class implements MatomoTranslator
        {
            public function translate(string $key, string $language, array $arguments = []): string
            {
                return [
                    'Intl_Currency_USD' => 'US Dollar',
                    'Intl_CurrencySymbol_USD' => '$',
                    'Intl_Currency_BTC' => 'Bitcoin',
                    'Intl_CurrencySymbol_BTC' => '₿',
                ][$key] ?? $key;
            }
        };
        $currencies = new ConfiguredCurrencyProvider(
            currencies: [
                'USD' => ['$', 'US dollar'],
                'INVALID' => 'not currency data',
            ],
            customCurrencies: ['BTC' => 'Bitcoin'],
            translator: $translator,
        );

        $this->assertSame([
            'USD' => '$',
            'BTC' => 'BTC',
        ], $currencies->symbols());
        $this->assertSame([
            'BTC' => 'Bitcoin (₿)',
            'USD' => 'US Dollar ($)',
        ], $currencies->names('en'));
    }
}
