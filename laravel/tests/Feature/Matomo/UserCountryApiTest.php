<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Geolocation\CountryMetadataProvider;
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

    public function test_rejects_country_reports_without_view_access(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('getCountry'))->assertUnauthorized();
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
        };
    }
}
