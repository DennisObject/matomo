<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Sites\LocalizedTimezoneProvider;
use PHPUnit\Framework\TestCase;

class LocalizedTimezoneProviderTest extends TestCase
{
    public function test_matches_matomo_timezone_labels_and_fallbacks(): void
    {
        $translator = new class implements MatomoTranslator
        {
            public function translate(string $key, string $language, array $arguments = []): string
            {
                $translation = [
                    'SitesManager_Format_Utc' => 'UTC%s',
                    'Intl_Continent_asi' => 'Asia',
                    'Intl_Continent_amn' => 'North America',
                    'Intl_Country_JP' => 'Japan',
                    'Intl_Country_US' => 'United States',
                    'Intl_Timezone_America_NewYork' => 'New York',
                ][$key] ?? $key;

                return $arguments === [] ? $translation : vsprintf($translation, $arguments);
            }
        };
        $timezones = new LocalizedTimezoneProvider($translator, [
            'jp' => 'asi',
            'us' => 'amn',
        ]);

        $this->assertSame('UTC+1:30', $timezones->name('UTC+1.5', 'en'));
        $this->assertSame('Japan', $timezones->name('Asia/Tokyo', 'en', 'JP', false));
        $this->assertSame(
            'United States - New York',
            $timezones->name('America/New_York', 'en', 'US', true),
        );
        $this->assertSame(
            'United States - Missing City',
            $timezones->name('Area/Missing_City', 'en', 'US', true),
        );
        $this->assertSame('Some City', $timezones->name('Invalid/Some_City', 'en'));

        $groups = $timezones->all('en', true);

        $this->assertSame(['Asia', 'North America', 'UTC'], array_keys($groups));
        $this->assertSame('Japan', $groups['Asia']['Asia/Tokyo']);
        $this->assertSame('United States - New York', $groups['North America']['America/New_York']);
        $this->assertSame('UTC+5:45', $groups['UTC']['UTC+5.75']);
        $this->assertSame(['UTC'], array_keys($timezones->all('en', false)));
    }
}
