<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class CustomDimensionsApiTest extends TestCase
{
    private ApiAccessAuthorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $this->authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $this->authorizer);
        $this->app->instance(CustomDimensionRepository::class, new FakeCustomDimensionRepository);

        $visitMetrics = $this->createStub(VisitsSummaryArchiveRepository::class);
        $visitMetrics->method('metrics')->willReturn([]);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $visitMetrics);
    }

    public function test_returns_configured_dimensions_and_filters_hidden_scope_method(): void
    {
        $this->get($this->url('getConfiguredCustomDimensions', ['idSite' => 1]))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.idcustomdimension', '1')
            ->assertJsonPath('0.active', true)
            ->assertJsonPath('1.extractions.0.dimension', 'url');

        $this->get($this->url('getConfiguredCustomDimensionsHavingScope', [
            'idSite' => 1,
            'scope' => 'action',
        ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Page category');
    }

    public function test_returns_available_scope_capacity(): void
    {
        $this->get($this->url('getAvailableScopes', ['idSite' => 1]))
            ->assertOk()
            ->assertJsonPath('0.value', 'visit')
            ->assertJsonPath('0.numSlotsAvailable', 2)
            ->assertJsonPath('0.numSlotsUsed', 1)
            ->assertJsonPath('0.numSlotsLeft', 1)
            ->assertJsonPath('0.supportsExtractions', false)
            ->assertJsonPath('1.value', 'action')
            ->assertJsonPath('1.numSlotsAvailable', 3)
            ->assertJsonPath('1.numSlotsUsed', 1)
            ->assertJsonPath('1.supportsExtractions', true);
    }

    public function test_returns_localized_extraction_dimensions(): void
    {
        $this->get($this->url('getAvailableExtractionDimensions'))
            ->assertOk()
            ->assertExactJson([
                ['value' => 'url', 'name' => 'Page URL'],
                ['value' => 'urlparam', 'name' => 'Page URL Parameter'],
                ['value' => 'action_name', 'name' => 'Page Title'],
            ]);
    }

    public function test_enforces_view_and_write_access(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getConfiguredCustomDimensions', ['idSite' => 1]))
            ->assertUnauthorized();
        $this->get($this->url('getAvailableExtractionDimensions'))
            ->assertUnauthorized();
    }

    public function test_returns_expanded_and_flat_custom_dimension_reports(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn($this->archiveRecords());
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $parameters = [
            'idDimension' => 2,
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-14',
        ];

        $this->get($this->url('getCustomDimension', [...$parameters, 'expanded' => 1]))
            ->assertOk()
            ->assertJsonPath('0.label', 'en')
            ->assertJsonPath('0.nb_hits_percent_of_total', '100%')
            ->assertJsonPath('0.avg_time_on_dimension', 180)
            ->assertJsonPath('0.exit_rate', '50%')
            ->assertJsonPath('0.segment', 'dimension2==en')
            ->assertJsonPath('0.subtable.0.label', 'example.com/en')
            ->assertJsonPath('0.subtable.0.nb_hits_percent_of_total', '50%')
            ->assertJsonPath('0.subtable.0.segment', 'dimension2==en;actionUrl=$example.com%2Fen');

        $this->get($this->url('getCustomDimension', [...$parameters, 'flat' => 1]))
            ->assertOk()
            ->assertJsonPath('0.label', 'en - example.com/en')
            ->assertJsonPath('0.CustomDimension_CustomDimension2', 'en - example.com/en');
    }

    public function test_returns_a_requested_custom_dimension_subtable(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn($this->archiveRecords());
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getCustomDimension', [
            'idDimension' => 2,
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-14',
            'idSubtable' => 10,
        ]))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('1.label', 'example.com/about')
            ->assertJsonPath('1.segment', 'dimension2==en;actionUrl=$example.com%2Fabout');
    }

    public function test_rejects_missing_and_inactive_dimensions(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);

        foreach ([3, 99] as $dimensionId) {
            $this->get($this->url('getCustomDimension', [
                'idDimension' => $dimensionId,
                'idSite' => 1,
                'period' => 'day',
                'date' => '2026-08-14',
            ]))->assertBadRequest();
        }
    }

    public function test_keeps_zero_user_metric_when_the_site_has_user_data(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn($this->archiveRecords());
        $visitMetrics = $this->createStub(VisitsSummaryArchiveRepository::class);
        $visitMetrics->method('metrics')->willReturn([
            1 => ['2026-08-14,2026-08-14' => ['nb_users' => 2]],
        ]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $visitMetrics);

        $this->get($this->url('getCustomDimension', [
            'idDimension' => 2,
            'idSite' => 1,
            'period' => 'day',
            'date' => '2026-08-14',
        ]))->assertOk()->assertJsonPath('0.nb_users', 0);
    }

    /** @return array<int, array<string, array<string, list<array{columns: array<string, int|string>, metadata: array{}, subtableId: int|null}>>>> */
    private function archiveRecords(): array
    {
        return [1 => ['2026-08-14,2026-08-14' => [
            'CustomDimensions_Dimension2' => [[
                'columns' => [
                    'label' => 'en',
                    'nb_visits' => 2,
                    'nb_users' => 0,
                    'nb_hits' => 2,
                    'sum_time_spent' => 360,
                    'bounce_count' => 0,
                    'exit_nb_visits' => 1,
                ],
                'metadata' => [],
                'subtableId' => 10,
            ]],
            'CustomDimensions_Dimension2_10' => [
                [
                    'columns' => [
                        'label' => 'example.com/en',
                        'nb_visits' => 1,
                        'nb_users' => 0,
                        'nb_hits' => 1,
                        'sum_time_spent' => 0,
                        'bounce_count' => 0,
                        'exit_nb_visits' => 1,
                    ],
                    'metadata' => [],
                    'subtableId' => null,
                ],
                [
                    'columns' => [
                        'label' => 'example.com/about',
                        'nb_visits' => 1,
                        'nb_users' => 0,
                        'nb_hits' => 1,
                        'sum_time_spent' => 360,
                        'bounce_count' => 0,
                        'exit_nb_visits' => 0,
                    ],
                    'metadata' => [],
                    'subtableId' => null,
                ],
            ],
        ]]];
    }

    /** @param array<string, int|string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'CustomDimensions.'.$method,
            'format' => 'json',
            'token_auth' => 'test-token',
            ...$parameters,
        ]);
    }
}

final class FakeCustomDimensionRepository implements CustomDimensionRepository
{
    public function configuredForSite(int $siteId): array
    {
        return [
            [
                'idcustomdimension' => '1',
                'idsite' => (string) $siteId,
                'name' => 'Customer type',
                'description' => '',
                'index' => '1',
                'scope' => 'visit',
                'active' => true,
                'extractions' => [],
                'case_sensitive' => true,
            ],
            [
                'idcustomdimension' => '2',
                'idsite' => (string) $siteId,
                'name' => 'Page category',
                'description' => 'Category from URL',
                'index' => '2',
                'scope' => 'action',
                'active' => true,
                'extractions' => [['dimension' => 'url', 'pattern' => '/category/(.+)']],
                'case_sensitive' => false,
            ],
        ];
    }

    public function find(int $siteId, int $dimensionId): ?array
    {
        if ($dimensionId === 3) {
            return [
                'idcustomdimension' => '3',
                'idsite' => (string) $siteId,
                'name' => 'Inactive',
                'description' => '',
                'index' => '3',
                'scope' => 'visit',
                'active' => false,
                'extractions' => [],
                'case_sensitive' => true,
            ];
        }

        foreach ($this->configuredForSite($siteId) as $dimension) {
            if ((int) $dimension['idcustomdimension'] === $dimensionId) {
                return $dimension;
            }
        }

        return null;
    }

    public function installedIndexes(string $scope): array
    {
        return $scope === 'visit' ? [1, 2] : [1, 2, 3];
    }
}
