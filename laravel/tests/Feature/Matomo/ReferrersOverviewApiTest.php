<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ReferrersOverviewApiTest extends TestCase
{
    public function test_combines_referrer_type_visits_distinct_counts_and_percentages(): void
    {
        $this->bindViewAccess();
        $this->bindArchives([
            $this->typeRow(1, 4),
            $this->typeRow(2, 3),
            $this->typeRow(3, 2),
            $this->typeRow(6, 1),
        ], [
            'Referrers_distinctSearchEngines' => 2,
            'Referrers_distinctKeywords' => 3,
            'Referrers_distinctWebsites' => 2,
            'Referrers_distinctWebsitesUrls' => 4,
            'Referrers_distinctCampaigns' => 1,
        ]);

        $this->get($this->url())->assertOk()->assertExactJson([
            'Referrers_visitorsFromDirectEntry' => 4,
            'Referrers_visitorsFromSearchEngines' => 3,
            'Referrers_visitorsFromWebsites' => 2,
            'Referrers_visitorsFromCampaigns' => 1,
            'Referrers_visitorsFromSocialNetworks' => 0,
            'Referrers_visitorsFromAIAssistants' => 0,
            'Referrers_visitorsFromDirectEntry_percent' => '40%',
            'Referrers_visitorsFromSearchEngines_percent' => '30%',
            'Referrers_visitorsFromWebsites_percent' => '20%',
            'Referrers_visitorsFromCampaigns_percent' => '10%',
            'Referrers_visitorsFromSocialNetworks_percent' => '0%',
            'Referrers_visitorsFromAIAssistants_percent' => '0%',
            'Referrers_distinctSearchEngines' => 2,
            'Referrers_distinctSocialNetworks' => 0,
            'Referrers_distinctAIAssistants' => 0,
            'Referrers_distinctKeywords' => 3,
            'Referrers_distinctWebsites' => 2,
            'Referrers_distinctWebsitesUrls' => 4,
            'Referrers_distinctCampaigns' => 1,
        ]);
    }

    public function test_filters_requested_columns(): void
    {
        $this->bindViewAccess();
        $this->bindArchives([$this->typeRow(2, 3)], ['Referrers_distinctKeywords' => 2]);

        $this->get($this->url().'&columns=Referrers_visitorsFromSearchEngines,Referrers_distinctKeywords')
            ->assertOk()
            ->assertExactJson([
                'Referrers_visitorsFromSearchEngines' => 3,
                'Referrers_distinctKeywords' => 2,
            ]);
    }

    public function test_returns_unformatted_percentage_quotients(): void
    {
        $this->bindViewAccess();
        $this->bindArchives([
            $this->typeRow(1, 1),
            $this->typeRow(2, 2),
        ], []);

        $this->get($this->url().'&format_metrics=0&columns='.
            'Referrers_visitorsFromDirectEntry_percent,'.
            'Referrers_visitorsFromSearchEngines_percent')
            ->assertOk()
            ->assertExactJson([
                'Referrers_visitorsFromDirectEntry_percent' => 0.3333,
                'Referrers_visitorsFromSearchEngines_percent' => 0.6667,
            ]);
    }

    public function test_passes_the_segment_hash_to_both_archive_sources(): void
    {
        $this->bindViewAccess();
        $hash = md5('countryCode==fr');
        $tables = $this->createMock(BlobArchiveRepository::class);
        $tables->expects($this->once())->method('rows')->with(
            [7],
            $this->isType('array'),
            $hash,
            'Referrers_type',
        )->willReturn([]);
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())->method('pluginMetrics')->with(
            [7],
            $this->isType('array'),
            $hash,
            $this->isType('array'),
            'Referrers',
        )->willReturn([]);
        $this->app->instance(BlobArchiveRepository::class, $tables);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url().'&segment=countryCode%3D%3Dfr')->assertOk();
    }

    public function test_rejects_access_before_reading_archives(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $tables = $this->createMock(BlobArchiveRepository::class);
        $tables->expects($this->never())->method('rows');
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->never())->method('pluginMetrics');
        $this->app->instance(BlobArchiveRepository::class, $tables);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url())->assertUnauthorized();
    }

    public function test_returns_a_date_map_for_multiple_dates(): void
    {
        $this->bindViewAccess();
        $tables = $this->createStub(BlobArchiveRepository::class);
        $tables->method('rows')->willReturn([
            7 => [
                '2026-08-13,2026-08-13' => [$this->typeRow(1, 2)],
                '2026-08-14,2026-08-14' => [$this->typeRow(1, 4)],
            ],
        ]);
        $numbers = $this->createStub(NumericArchiveRepository::class);
        $numbers->method('pluginMetrics')->willReturn([]);
        $this->app->instance(BlobArchiveRepository::class, $tables);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get(str_replace('date=2026-08-14', 'date=2026-08-13,2026-08-14', $this->url()).
            '&columns=Referrers_visitorsFromDirectEntry')
            ->assertOk()
            ->assertExactJson([
                '2026-08-13' => ['Referrers_visitorsFromDirectEntry' => 2],
                '2026-08-14' => ['Referrers_visitorsFromDirectEntry' => 4],
            ]);
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

    /**
     * @param  list<array{columns: array{label: int, nb_visits: int}, metadata: array{}}>  $types
     * @param  array<string, int|float>  $numbers
     */
    private function bindArchives(array $types, array $numbers): void
    {
        $tables = $this->createStub(BlobArchiveRepository::class);
        $tables->method('rows')->willReturn([
            7 => ['2026-08-14,2026-08-14' => $types],
        ]);
        $numeric = $this->createStub(NumericArchiveRepository::class);
        $numeric->method('pluginMetrics')->willReturn([
            7 => ['2026-08-14,2026-08-14' => $numbers],
        ]);
        $this->app->instance(BlobArchiveRepository::class, $tables);
        $this->app->instance(NumericArchiveRepository::class, $numeric);
    }

    /** @return array{columns: array{label: int, nb_visits: int}, metadata: array{}} */
    private function typeRow(int $type, int $visits): array
    {
        return ['columns' => ['label' => $type, 'nb_visits' => $visits], 'metadata' => []];
    }

    private function url(): string
    {
        return '/index.php?module=API&method=Referrers.get&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token';
    }
}
