<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Geolocation\GeolocationProviderRegistry;
use App\Matomo\Geolocation\GeolocationSettings;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Reporting\BlobArchive;
use App\Matomo\Reporting\BlobArchiveMetadataRepository;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class UserCountryApiTest extends TestCase
{
    public function test_returns_localized_country_and_continent_reports(): void
    {
        $this->bindViewAccess();
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([
            7 => ['2026-08-14,2026-08-14' => [
                $this->row('ca', 2),
                $this->row('fr', 6),
                $this->row('ti', 2),
            ]],
        ]);
        $this->app->instance(BlobArchiveRepository::class, $archives);
        $this->app->instance(CountryMetadataProvider::class, $this->countries());

        $this->get($this->url('getCountry'))->assertOk()->assertExactJson([
            [
                'label' => 'Canada',
                'nb_visits' => 2.0,
                'nb_actions' => 4.0,
                'nb_visits_percent_of_total' => '20%',
                'nb_actions_percent_of_total' => '20%',
                'code' => 'ca',
                'logo' => 'flags/ca.png',
                'segment' => 'countryCode==ca',
                'logoHeight' => 16,
            ],
            [
                'label' => 'France',
                'nb_visits' => 6.0,
                'nb_actions' => 12.0,
                'nb_visits_percent_of_total' => '60%',
                'nb_actions_percent_of_total' => '60%',
                'code' => 'fr',
                'logo' => 'flags/fr.png',
                'segment' => 'countryCode==fr',
                'logoHeight' => 16,
            ],
            [
                'label' => 'China',
                'nb_visits' => 2.0,
                'nb_actions' => 4.0,
                'nb_visits_percent_of_total' => '20%',
                'nb_actions_percent_of_total' => '20%',
                'code' => 'cn',
                'logo' => 'flags/cn.png',
                'segment' => 'countryCode==cn',
                'logoHeight' => 16,
            ],
        ]);

        $this->get($this->url('getContinent'))->assertOk()->assertExactJson([
            [
                'label' => 'North America',
                'nb_visits' => 2.0,
                'nb_actions' => 4.0,
                'nb_visits_percent_of_total' => '20%',
                'nb_actions_percent_of_total' => '20%',
                'code' => 'North America',
            ],
            [
                'label' => 'Europe',
                'nb_visits' => 6.0,
                'nb_actions' => 12.0,
                'nb_visits_percent_of_total' => '60%',
                'nb_actions_percent_of_total' => '60%',
                'code' => 'Europe',
            ],
            [
                'label' => 'Asia',
                'nb_visits' => 2.0,
                'nb_actions' => 4.0,
                'nb_visits_percent_of_total' => '20%',
                'nb_actions_percent_of_total' => '20%',
                'code' => 'Asia',
            ],
        ]);
    }

    public function test_returns_country_mapping_without_site_access(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));
        $this->app->instance(CountryMetadataProvider::class, $this->countries());

        $this->get(
            '/index.php?module=API&method=UserCountry.getCountryCodeMapping&format=json',
        )->assertOk()->assertExactJson([
            'ca' => 'Canada',
            'fr' => 'France',
            'cn' => 'China',
        ]);
    }

    public function test_reads_the_distinct_country_metric_from_the_user_country_archive(): void
    {
        $this->bindViewAccess();
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())->method('pluginMetrics')->with(
            [7],
            $this->isType('array'),
            '',
            ['UserCountry_distinctCountries'],
            'UserCountry',
        )->willReturn([
            7 => ['2026-08-14,2026-08-14' => ['UserCountry_distinctCountries' => 3]],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url('getNumberOfDistinctCountries'))
            ->assertOk()
            ->assertContent('3');
    }

    public function test_returns_region_and_city_metadata(): void
    {
        $this->bindViewAccess();
        $archives = $this->createMock(BlobArchiveMetadataRepository::class);
        $archives->expects($this->exactly(2))->method('archives')->willReturnCallback(
            fn (array $siteIds, array $periods, string $segmentHash, string $record): array => [
                7 => ['2026-08-14,2026-08-14' => new BlobArchive(
                    $record === 'UserCountry_city'
                        ? [
                            $this->row('Besançon|BFC|fr|47.25|6.02', 3),
                            $this->row('xx|xx|xx', 1),
                        ]
                        : [
                            $this->row('BFC|fr', 3),
                            $this->row('xx|xx', 1),
                        ],
                    '2026-08-15 00:00:00',
                )],
            ],
        );
        $this->app->instance(BlobArchiveMetadataRepository::class, $archives);
        $this->app->instance(CountryMetadataProvider::class, $this->countries());

        $this->get($this->url('getRegion'))->assertOk()->assertExactJson([
            [
                'label' => 'Bourgogne-Franche-Comté, France',
                'nb_visits' => 3.0,
                'nb_actions' => 6.0,
                'nb_visits_percent_of_total' => '75%',
                'nb_actions_percent_of_total' => '75%',
                'region' => 'BFC',
                'country' => 'fr',
                'country_name' => 'France',
                'region_name' => 'Bourgogne-Franche-Comté',
                'logo' => 'flags/fr.png',
                'segment' => 'regionCode==BFC;countryCode==fr',
            ],
            [
                'label' => 'Unknown',
                'nb_visits' => 1.0,
                'nb_actions' => 2.0,
                'nb_visits_percent_of_total' => '25%',
                'nb_actions_percent_of_total' => '25%',
                'region' => 'xx',
                'country' => 'xx',
                'country_name' => 'Unknown',
                'region_name' => 'Unknown',
                'logo' => 'flags/xx.png',
            ],
        ]);
        $this->get($this->url('getCity'))->assertOk()->assertExactJson([
            [
                'label' => 'Besançon, Bourgogne-Franche-Comté, France',
                'nb_visits' => 3.0,
                'nb_actions' => 6.0,
                'nb_visits_percent_of_total' => '75%',
                'nb_actions_percent_of_total' => '75%',
                'region' => 'BFC',
                'country' => 'fr',
                'country_name' => 'France',
                'region_name' => 'Bourgogne-Franche-Comté',
                'logo' => 'flags/fr.png',
                'city_name' => 'Besançon',
                'lat' => '47.25',
                'long' => '6.02',
                'segment' => 'city==Besan%C3%A7on;regionCode==BFC;countryCode==fr',
            ],
            [
                'label' => 'Unknown',
                'nb_visits' => 1.0,
                'nb_actions' => 2.0,
                'nb_visits_percent_of_total' => '25%',
                'nb_actions_percent_of_total' => '25%',
                'region' => 'xx',
                'country' => 'xx',
                'country_name' => 'Unknown',
                'region_name' => 'Unknown',
                'logo' => 'flags/xx.png',
                'city_name' => 'Unknown',
                'city' => 'xx',
            ],
        ]);
    }

    public function test_converts_legacy_region_codes_only_for_archives_before_the_iso_switch(): void
    {
        $this->bindViewAccess();
        $archives = $this->createStub(BlobArchiveMetadataRepository::class);
        $archives->method('archives')->willReturn([
            7 => ['2026-08-14,2026-08-14' => new BlobArchive(
                [$this->row('01|fr', 1)],
                '2026-08-14 00:00:00',
            )],
        ]);
        $options = $this->createStub(OptionRepository::class);
        $options->method('value')->willReturnCallback(static fn (string $name): ?string => match ($name) {
            'usercountry.switchtoisoregions' => (string) strtotime('2026-08-15 00:00:00 UTC'),
            default => null,
        });
        $this->app->instance(BlobArchiveMetadataRepository::class, $archives);
        $this->app->instance(OptionRepository::class, $options);
        $this->app->instance(CountryMetadataProvider::class, $this->countries());

        $this->get($this->url('getRegion'))
            ->assertOk()
            ->assertJsonPath('0.region', 'BFC')
            ->assertJsonPath('0.country', 'fr');
    }

    public function test_rejects_country_reports_without_view_access(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('getCountry'))->assertUnauthorized();
    }

    public function test_geolocates_an_ip_with_an_explicit_provider(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(CountryMetadataProvider::class, $this->countries());

        $providers = $this->createMock(GeolocationProviderRegistry::class);
        $providers->expects($this->once())->method('locate')->with(
            '198.51.100.10',
            'fr-FR,fr;q=0.9',
            '192.0.2.20',
            'geoip2php',
        )->willReturn([
            'country_code' => 'fr',
            'lat' => 48.85661,
            'long' => 2.35222,
        ]);
        $this->app->instance(GeolocationProviderRegistry::class, $providers);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->get('/index.php?module=API&method=UserCountry.getLocationFromIP'.
                '&ip=198.51.100.10&provider=geoip2php&format=json&token_auth=view-token')
            ->assertOk()
            ->assertExactJson([
                'country_code' => 'fr',
                'lat' => 48.86,
                'long' => 2.35,
                'continent_code' => 'eur',
                'continent_name' => 'Europe',
                'country_name' => 'France',
                'ip' => '198.51.100.10',
            ]);
    }

    public function test_rejects_ip_geolocation_without_view_access(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get('/index.php?module=API&method=UserCountry.getLocationFromIP'.
            '&format=json&token_auth=view-token')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'You must have view access to at least one website.');
    }

    public function test_empty_legacy_ip_and_provider_values_use_request_defaults(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeViewAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $providers = $this->createMock(GeolocationProviderRegistry::class);
        $providers->expects($this->once())->method('locate')->with(
            '192.0.2.20',
            $this->isType('string'),
            '192.0.2.20',
            null,
        )->willReturn(['country_code' => 'fr']);
        $this->app->instance(GeolocationProviderRegistry::class, $providers);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->get('/index.php?module=API&method=UserCountry.getLocationFromIP'.
                '&ip=0&provider=0&format=json&token_auth=view-token')
            ->assertOk()
            ->assertJsonPath('ip', '192.0.2.20');
    }

    public function test_sets_the_location_provider_for_a_superuser(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $providers = $this->createMock(GeolocationProviderRegistry::class);
        $providers->expects($this->once())->method('setCurrent')->with('default');
        $this->app->instance(GeolocationProviderRegistry::class, $providers);

        $this->get('/index.php?module=API&method=UserCountry.setLocationProvider'.
            '&providerId=default&format=json&token_auth=super-token')
            ->assertOk()
            ->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_rejects_provider_changes_when_geolocation_admin_is_disabled(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $settings = $this->createStub(GeolocationSettings::class);
        $settings->method('adminEnabled')->willReturn(false);
        $this->app->instance(GeolocationSettings::class, $settings);

        $this->get('/index.php?module=API&method=UserCountry.setLocationProvider'.
            '&providerId=default&format=json&token_auth=super-token')
            ->assertBadRequest()
            ->assertJsonPath('message', 'Setting geo location has been disabled in config.');
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=UserCountry.{$method}&idSite=7".
            '&period=day&date=2026-08-14&format=json&token_auth=view-token';
    }

    /** @return array{columns: array{label: string, nb_visits: int, nb_actions: int}, metadata: array{}} */
    private function row(string $label, int $visits): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits, 'nb_actions' => $visits * 2],
            'metadata' => [],
        ];
    }

    private function countries(): CountryMetadataProvider
    {
        return new class implements CountryMetadataProvider
        {
            public function codes(): array
            {
                return ['ca', 'fr', 'cn'];
            }

            public function continentCode(string $countryCode): string
            {
                return ['ca' => 'amn', 'fr' => 'eur', 'cn' => 'asi'][$countryCode] ?? 'unk';
            }

            public function countryName(string $countryCode, string $language): string
            {
                return ['ca' => 'Canada', 'fr' => 'France', 'cn' => 'China'][$countryCode] ?? 'Unknown';
            }

            public function continentName(string $continentCode, string $language): string
            {
                return ['amn' => 'North America', 'eur' => 'Europe', 'asi' => 'Asia'][$continentCode] ?? 'Unknown';
            }

            public function flag(string $countryCode): string
            {
                return "flags/{$countryCode}.png";
            }

            public function regionName(string $countryCode, string $regionCode, string $language): string
            {
                if ($countryCode === 'fr' && $regionCode === 'BFC') {
                    return 'Bourgogne-Franche-Comté';
                }

                return 'Unknown';
            }

            public function regionCodeForName(string $countryCode, string $regionName): string
            {
                return '';
            }

            public function convertLegacyRegion(string $countryCode, string $regionCode): array
            {
                return strtolower($countryCode) === 'fr' && $regionCode === '01'
                    ? ['country' => 'fr', 'region' => 'BFC']
                    : ['country' => strtolower($countryCode), 'region' => $regionCode];
            }
        };
    }
}
