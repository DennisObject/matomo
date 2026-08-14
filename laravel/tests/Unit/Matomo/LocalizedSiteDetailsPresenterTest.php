<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Sites\LocalizedSiteDetailsPresenter;
use App\Matomo\Sites\TimezoneProvider;
use PHPUnit\Framework\TestCase;

class LocalizedSiteDetailsPresenterTest extends TestCase
{
    public function test_adds_local_names_and_hides_creator_from_regular_users(): void
    {
        $timezones = $this->createMock(TimezoneProvider::class);
        $timezones->expects($this->once())
            ->method('name')
            ->with('Europe/Paris', 'fr')
            ->willReturn('France');
        $translator = $this->createMock(MatomoTranslator::class);
        $translator->expects($this->once())
            ->method('translate')
            ->with('Intl_Currency_EUR', 'fr')
            ->willReturn('euro');
        $presenter = new LocalizedSiteDetailsPresenter($timezones, $translator);

        $this->assertSame([
            'idsite' => 7,
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'timezone_name' => 'France',
            'currency_name' => 'euro',
        ], $presenter->present([
            'idsite' => 7,
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'creator_login' => 'owner',
        ], 'fr', false));
    }
}
