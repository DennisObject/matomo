<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RowEvolutionApiTest extends TestCase
{
    public function test_returns_single_row_evolution_and_reuses_verified_authentication(): void
    {
        $authentications = [];
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(2))
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturnCallback(static function (ApiAuthentication $authentication) use (&$authentications): bool {
                $authentications[] = $authentication;

                return true;
            });
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->bindReport([
            '2026-08-14,2026-08-14' => $this->row('1', 2, 4),
            '2026-08-15,2026-08-15' => $this->row('1', 4, 5),
        ]);

        $response = $this->get($this->url())->assertOk();

        $response->assertJsonPath('label', 'Direct Entry')
            ->assertJsonPath('reportData.2026-08-14.0.nb_visits', 2)
            ->assertJsonPath('reportData.2026-08-15.0.nb_visits', 4)
            ->assertJsonMissingPath('reportData.2026-08-14.0.label')
            ->assertJsonPath('metadata.metrics.nb_visits.name', 'Visits')
            ->assertJsonPath('metadata.metrics.nb_visits.min', 2)
            ->assertJsonPath('metadata.metrics.nb_visits.max', 4)
            ->assertJsonPath('metadata.metrics.nb_visits.change', '+100%')
            ->assertJsonPath('metadata.dimension', 'Channel Type');
        $this->assertCount(2, $authentications);
        $this->assertSame($authentications[0], $authentications[1]);
    }

    public function test_keeps_empty_dates_after_the_selected_row_has_data(): void
    {
        $this->bindAccess(true);
        $this->bindReport([
            '2026-08-14,2026-08-14' => $this->row('1', 2, 4),
            '2026-08-15,2026-08-15' => $this->row('2', 4, 5),
        ]);

        $this->get($this->url())->assertOk()
            ->assertJsonPath('reportData.2026-08-15', [])
            ->assertJsonPath('metadata.metrics.nb_visits.min', 0)
            ->assertJsonPath('metadata.metrics.nb_visits.max', 2)
            ->assertJsonPath('metadata.metrics.nb_visits.change', '-100%');
    }

    public function test_propagates_nested_report_errors(): void
    {
        $this->bindAccess(true);
        $settings = $this->createMock(ReportingSettings::class);
        $settings->expects($this->once())->method('periodEnabled')->with('day')->willReturn(false);
        $this->app->instance(ReportingSettings::class, $settings);

        $this->get($this->url())->assertBadRequest()->assertExactJson([
            'result' => 'error',
            'message' => "The period 'day' is not enabled.",
        ]);
    }

    public function test_rejects_reports_without_a_dimension(): void
    {
        $this->bindAccess(true);

        $this->get($this->url([
            'apiModule' => 'VisitsSummary',
            'apiAction' => 'get',
        ]))->assertStatus(501)->assertJsonPath(
            'message',
            'Report VisitsSummary.get is not supported by row evolution.',
        );
    }

    /** @param array<string, string> $parameters */
    #[DataProvider('unsupportedVariants')]
    public function test_rejects_unported_row_variants(array $parameters, string $message): void
    {
        $this->bindAccess(true);

        $this->get($this->url($parameters))->assertStatus(501)->assertJsonPath('message', $message);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function unsupportedVariants(): iterable
    {
        yield 'automatic labels' => [
            ['label' => 'false'],
            'Automatic and multi-row evolution labels have not moved to Laravel yet.',
        ];
        yield 'multiple labels' => [
            ['label' => 'Direct Entry,Search Engines'],
            'Multi-row and recursive row evolution labels have not moved to Laravel yet.',
        ];
        yield 'recursive labels' => [
            ['label' => 'example.test>page'],
            'Multi-row and recursive row evolution labels have not moved to Laravel yet.',
        ];
        yield 'goal metrics' => [
            ['idGoal' => '2'],
            'Goal, dimension, and comparison-series row evolution variants have not moved to Laravel yet.',
        ];
    }

    public function test_requires_multiple_periods(): void
    {
        $this->bindAccess(true);
        $this->bindReport(['2026-08-14,2026-08-14' => $this->row('1', 2, 4)]);

        $this->get($this->url(['date' => '2026-08-14']))
            ->assertBadRequest()
            ->assertJsonPath('message', 'Row evolution requires a date that resolves to multiple periods.');
    }

    public function test_rejects_invalid_site_ids_before_authorization(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url(['idSite' => '0']))->assertBadRequest();
    }

    public function test_rejects_site_without_view_access(): void
    {
        $this->bindAccess(false);
        $this->get($this->url())->assertUnauthorized();
    }

    private function bindAccess(bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    /**
     * @param  array<string, array{columns: array{label: string, nb_visits: int, nb_actions: int}, metadata: array{}, subtableId: null}>  $rows
     */
    private function bindReport(array $rows): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);

        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturnCallback(
            static function (array $siteIds, array $periods, string $segment, string $record) use ($rows): array {
                $result = [];
                foreach ($periods as $period) {
                    if (! $period instanceof ReportingPeriod) {
                        continue;
                    }

                    $result[$period->rangeKey()] = [
                        $record => isset($rows[$period->rangeKey()]) ? [$rows[$period->rangeKey()]] : [],
                    ];
                }

                return [7 => $result];
            },
        );
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
    }

    /** @return array{columns: array{label: string, nb_visits: int, nb_actions: int}, metadata: array{}, subtableId: null} */
    private function row(string $label, int $visits, int $actions): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits, 'nb_actions' => $actions],
            'metadata' => [],
            'subtableId' => null,
        ];
    }

    /** @param array<string, string> $parameters */
    private function url(array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'API.getRowEvolution',
            'format' => 'json',
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-14,2026-08-15',
            'apiModule' => 'Referrers',
            'apiAction' => 'getReferrerType',
            'label' => 'Direct Entry',
            'column' => 'nb_visits',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
    }
}
