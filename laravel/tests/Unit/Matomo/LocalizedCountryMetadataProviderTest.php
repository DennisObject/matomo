<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Geolocation\LocalizedCountryMetadataProvider;
use App\Matomo\Localization\MatomoTranslator;
use PHPUnit\Framework\TestCase;

class LocalizedCountryMetadataProviderTest extends TestCase
{
    public function test_translates_location_names_and_normalizes_legacy_tibet_codes(): void
    {
        $translator = $this->createStub(MatomoTranslator::class);
        $translator->method('translate')->willReturnCallback(
            static fn (string $key): string => [
                'Intl_Country_CN' => 'China',
                'Intl_Continent_asi' => 'Asia',
                'General_Unknown' => 'Unknown',
            ][$key] ?? $key,
        );
        $provider = new LocalizedCountryMetadataProvider(
            ['cn' => 'asi'],
            ['CN' => ['14' => [
                'name' => 'Tibet Autonomous Region',
                'altNames' => ['Tibet Autonomous Région'],
            ]]],
            ['CN' => ['14' => 'Tibet']],
            ['CN' => ['01' => 'AH']],
            '/directory/that/does/not/exist',
            $translator,
        );

        $this->assertSame(['cn'], $provider->codes());
        $this->assertSame('asi', $provider->continentCode('TI'));
        $this->assertSame('China', $provider->countryName('ti', 'en'));
        $this->assertSame('Asia', $provider->continentName('asi', 'en'));
        $this->assertSame('Asia', $provider->continentName('ASI', 'en'));
        $this->assertSame('Unknown', $provider->countryName('xx', 'en'));
        $this->assertSame('plugins/Morpheus/icons/dist/flags/xx.png', $provider->flag('cn'));
        $this->assertSame('plugins/Morpheus/icons/dist/flags/xx.png', $provider->flag('../secret'));
        $this->assertSame('Tibet Autonomous Region', $provider->regionName('cn', '14', 'en'));
        $this->assertSame('14', $provider->regionCodeForName('cn', 'Tibet Autonomous Région'));
        $this->assertSame(
            ['country' => 'cn', 'region' => 'AH'],
            $provider->convertLegacyRegion('cn', '01'),
        );
    }
}
