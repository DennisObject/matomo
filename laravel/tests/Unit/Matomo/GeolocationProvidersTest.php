<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Geolocation\ConfiguredGeolocationProviderRegistry;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Geolocation\FileTrackerCacheInvalidator;
use App\Matomo\Geolocation\GeolocationProvider;
use App\Matomo\Geolocation\LanguageGeolocationProvider;
use App\Matomo\Geolocation\LocationResultCompleter;
use App\Matomo\Geolocation\MaxMindDatabaseGeolocationProvider;
use App\Matomo\Geolocation\ServerModuleGeolocationProvider;
use App\Matomo\Geolocation\ServerVariableMapping;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Options\MutableOptionRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GeolocationProvidersTest extends TestCase
{
    public function test_language_provider_uses_an_explicit_region_then_an_optional_language_guess(): void
    {
        $provider = new LanguageGeolocationProvider(
            enabled: true,
            guessCountryFromLanguage: true,
            countryCodes: ['fr', 'gb', 'us'],
            countriesByLanguage: ['fr' => 'fr'],
        );

        $this->assertTrue($provider->available());
        $this->assertSame(
            ['country_code' => 'us'],
            $provider->locate('192.0.2.1', 'en-US,en;q=0.9', '192.0.2.1'),
        );
        $this->assertSame(
            ['country_code' => 'fr'],
            $provider->locate('192.0.2.1', 'fr', '192.0.2.1'),
        );

        $disabledGuess = new LanguageGeolocationProvider(true, false, ['fr'], ['fr' => 'fr']);
        $this->assertSame(
            ['country_code' => 'xx'],
            $disabledGuess->locate('192.0.2.1', 'fr', '192.0.2.1'),
        );
    }

    public function test_location_completer_adds_names_and_normalizes_coordinates(): void
    {
        $completer = new LocationResultCompleter($this->countries());

        $this->assertSame([
            'country_code' => 'fr',
            'lat' => 47.24,
            'continent_code' => 'eur',
            'continent_name' => 'Europe',
            'country_name' => 'France',
        ], $completer->complete([
            'country_code' => 'fr',
            'lat' => '47.235',
            'long' => 'invalid',
        ], 'en'));
    }

    public function test_registry_selects_and_activates_available_providers(): void
    {
        $options = $this->options(['usercountry.location_provider' => 'one']);
        $provider = new class implements GeolocationProvider
        {
            public bool $activated = false;

            public function id(): string
            {
                return 'one';
            }

            public function available(): bool
            {
                return true;
            }

            public function locate(
                string $ipAddress,
                string $browserLanguage,
                string $currentIpAddress,
            ): array {
                return ['country_code' => 'fr'];
            }

            public function activate(): void
            {
                $this->activated = true;
            }
        };
        $cache = new class implements TrackerCacheInvalidator
        {
            public bool $cleared = false;

            public function clearGeneral(): void
            {
                $this->cleared = true;
            }
        };
        $registry = new ConfiguredGeolocationProviderRegistry([$provider], $options, $cache, 'missing');

        $this->assertSame(
            ['country_code' => 'fr'],
            $registry->locate('192.0.2.1', 'fr', '192.0.2.1'),
        );
        $registry->setCurrent('one');
        $this->assertTrue($provider->activated);
        $this->assertTrue($cache->cleared);
        $this->assertSame('one', $options->value('usercountry.location_provider'));

        $this->expectException(InvalidArgumentException::class);
        $registry->setCurrent('missing');
    }

    public function test_server_provider_reads_mapped_values_and_falls_back_for_a_forced_ip(): void
    {
        $mapping = new class implements ServerVariableMapping
        {
            public function variables(): array
            {
                return ['country_code' => 'TEST_COUNTRY', 'lat' => 'TEST_LATITUDE'];
            }
        };
        $fallback = new class implements GeolocationProvider
        {
            public function id(): string
            {
                return 'fallback';
            }

            public function available(): bool
            {
                return true;
            }

            public function locate(
                string $ipAddress,
                string $browserLanguage,
                string $currentIpAddress,
            ): array {
                return ['country_code' => 'gb'];
            }

            public function activate(): void {}
        };
        $provider = new ServerModuleGeolocationProvider($mapping, $fallback, $this->options());
        $oldCountry = $_SERVER['TEST_COUNTRY'] ?? null;
        $oldLatitude = $_SERVER['TEST_LATITUDE'] ?? null;

        try {
            $_SERVER['TEST_COUNTRY'] = 'FR';
            $_SERVER['TEST_LATITUDE'] = '47.24';

            $this->assertTrue($provider->available());
            $this->assertSame(
                ['country_code' => 'FR', 'lat' => '47.24'],
                $provider->locate('192.0.2.0', 'fr', '192.0.2.42'),
            );
            $this->assertSame(
                ['country_code' => 'gb'],
                $provider->locate('198.51.100.7', 'en-GB', '192.0.2.42'),
            );
        } finally {
            $this->restoreServerVariable('TEST_COUNTRY', $oldCountry);
            $this->restoreServerVariable('TEST_LATITUDE', $oldLatitude);
        }
    }

    public function test_server_provider_requires_a_location_identity_variable(): void
    {
        $mapping = new class implements ServerVariableMapping
        {
            public function variables(): array
            {
                return ['lat' => 'TEST_ONLY_LATITUDE'];
            }
        };
        $fallback = $this->createStub(GeolocationProvider::class);
        $oldLatitude = $_SERVER['TEST_ONLY_LATITUDE'] ?? null;

        try {
            $_SERVER['TEST_ONLY_LATITUDE'] = '47.24';

            $this->assertFalse((new ServerModuleGeolocationProvider(
                $mapping,
                $fallback,
                $this->options(),
            ))->available());
        } finally {
            $this->restoreServerVariable('TEST_ONLY_LATITUDE', $oldLatitude);
        }
    }

    public function test_maxmind_provider_is_unavailable_without_a_database_and_records_iso_activation(): void
    {
        $options = $this->options();
        $provider = new MaxMindDatabaseGeolocationProvider(
            '/directory/that/does/not/exist',
            true,
            $options,
            $this->countries(),
        );

        $this->assertFalse($provider->available());
        $this->assertNull($provider->locate('not-an-ip', 'fr', '192.0.2.1'));
        $provider->activate();
        $this->assertNotNull($options->value('usercountry.switchtoisoregions'));
    }

    public function test_maxmind_provider_detects_dated_dbip_databases(): void
    {
        $directory = '/dev/shm/geoip-test-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory));
        $database = $directory.'/dbip-city-lite-2026-08.mmdb';
        $this->assertNotFalse(file_put_contents($database, 'fixture'));

        try {
            $provider = new MaxMindDatabaseGeolocationProvider(
                $directory,
                false,
                $this->options(),
                $this->countries(),
            );

            $this->assertTrue($provider->available());
        } finally {
            unlink($database);
            rmdir($directory);
        }
    }

    public function test_file_cache_invalidator_removes_the_tracker_cache_file(): void
    {
        $path = tempnam('/dev/shm', 'tracker-cache-');
        $this->assertIsString($path);

        (new FileTrackerCacheInvalidator($path))->clearGeneral();

        $this->assertFileDoesNotExist($path);
    }

    /** @param array<string, string> $values */
    private function options(array $values = []): MutableOptionRepository
    {
        return new class($values) implements MutableOptionRepository
        {
            /** @param array<string, string> $values */
            public function __construct(private array $values) {}

            public function value(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, string $value, bool $autoload = false): void
            {
                $this->values[$name] = $value;
            }
        };
    }

    private function countries(): CountryMetadataProvider
    {
        return new class implements CountryMetadataProvider
        {
            public function codes(): array
            {
                return ['fr'];
            }

            public function continentCode(string $countryCode): string
            {
                return $countryCode === 'fr' ? 'eur' : 'unk';
            }

            public function countryName(string $countryCode, string $language): string
            {
                return $countryCode === 'fr' ? 'France' : 'Unknown';
            }

            public function continentName(string $continentCode, string $language): string
            {
                return $continentCode === 'eur' ? 'Europe' : 'Unknown';
            }

            public function flag(string $countryCode): string
            {
                return 'flags/'.$countryCode.'.png';
            }

            public function regionName(string $countryCode, string $regionCode, string $language): string
            {
                return $regionCode;
            }

            public function regionCodeForName(string $countryCode, string $regionName): string
            {
                return '';
            }

            public function convertLegacyRegion(string $countryCode, string $regionCode): array
            {
                return ['country' => $countryCode, 'region' => $regionCode];
            }
        };
    }

    private function restoreServerVariable(string $name, mixed $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name]);

            return;
        }

        $_SERVER[$name] = $value;
    }
}
