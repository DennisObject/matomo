<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Sites\ConfiguredCurrencyProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredCurrencyProviderTest extends TestCase
{
    public function test_custom_currencies_use_their_code_as_the_symbol(): void
    {
        $currencies = new ConfiguredCurrencyProvider(
            currencies: [
                'USD' => ['$', 'US dollar'],
                'INVALID' => 'not currency data',
            ],
            customCurrencies: ['BTC' => 'Bitcoin'],
        );

        $this->assertSame([
            'USD' => '$',
            'BTC' => 'BTC',
        ], $currencies->symbols());
    }
}
