<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\DbStats\ArchiveStorageRepository;
use App\Matomo\DbStats\DatabaseMetadataProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DbStatsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(DatabaseMetadataProvider::class, new FakeDatabaseMetadataProvider);
        $this->app->instance(ArchiveStorageRepository::class, new FakeArchiveStorageRepository);
    }

    public function test_returns_general_information_and_database_status(): void
    {
        $this->get($this->url('getGeneralInformation'))
            ->assertOk()
            ->assertExactJson([10, 5, 1680]);
        $this->get($this->url('getDBStatus'))
            ->assertOk()
            ->assertExactJson([
                'Uptime' => 1000,
                'Threads' => 3,
                'Questions' => 50,
                'Slow queries' => 2,
                'Flush tables' => 4,
                'Open tables' => 8,
                'Opens' => 'unavailable',
                'Queries per second avg' => 'unavailable',
            ]);
    }

    public function test_groups_database_usage_by_storage_family(): void
    {
        $this->get($this->url('getDatabaseUsageSummary'))
            ->assertOk()
            ->assertExactJson([
                [
                    'label' => 'tracker_data',
                    'data_size' => 1020,
                    'index_size' => 205,
                    'row_count' => 102,
                ],
                [
                    'label' => 'metric_data',
                    'data_size' => 120,
                    'index_size' => 12,
                    'row_count' => 12,
                ],
                [
                    'label' => 'report_data',
                    'data_size' => 60,
                    'index_size' => 6,
                    'row_count' => 6,
                ],
                [
                    'label' => 'other_data',
                    'data_size' => 220,
                    'index_size' => 37,
                    'row_count' => 22,
                ],
            ]);
    }

    /** @param list<array<string, int|string>> $expected */
    #[DataProvider('summaryProvider')]
    public function test_returns_table_and_year_summaries(string $method, array $expected): void
    {
        $this->get($this->url($method))->assertOk()->assertExactJson($expected);
    }

    /** @return iterable<string, array{string, list<array<string, int|string>>}> */
    public static function summaryProvider(): iterable
    {
        yield 'tracker' => ['getTrackerDataSummary', [[
            'label' => 'log_visit',
            'data_size' => 1000,
            'index_size' => 200,
            'row_count' => 100,
        ]]];
        yield 'numeric archives' => ['getMetricDataSummary', [
            ['label' => 'archive_numeric_2025_01', 'data_size' => 30, 'index_size' => 3, 'row_count' => 3],
            ['label' => 'archive_numeric_2025_02', 'data_size' => 40, 'index_size' => 4, 'row_count' => 4],
            ['label' => 'archive_numeric_2026_01', 'data_size' => 50, 'index_size' => 5, 'row_count' => 5],
        ]];
        yield 'numeric archives by year' => ['getMetricDataSummaryByYear', [
            ['label' => '2025', 'data_size' => 70, 'index_size' => 7, 'row_count' => 7],
            ['label' => '2026', 'data_size' => 50, 'index_size' => 5, 'row_count' => 5],
        ]];
        yield 'blob archives' => ['getReportDataSummary', [[
            'label' => 'archive_blob_2025_01',
            'data_size' => 60,
            'index_size' => 6,
            'row_count' => 6,
        ]]];
        yield 'blob archives by year' => ['getReportDataSummaryByYear', [[
            'label' => '2025',
            'data_size' => 60,
            'index_size' => 6,
            'row_count' => 6,
        ]]];
        yield 'admin tables' => ['getAdminDataSummary', [
            ['label' => 'site', 'data_size' => 100, 'index_size' => 20, 'row_count' => 10],
            ['label' => 'user', 'data_size' => 50, 'index_size' => 10, 'row_count' => 5],
            ['label' => 'log_profiling', 'data_size' => 20, 'index_size' => 5, 'row_count' => 2],
            ['label' => 'option', 'data_size' => 70, 'index_size' => 7, 'row_count' => 7],
        ]];
    }

    public function test_rejects_access_without_superuser_permission(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getDatabaseUsageSummary'))->assertUnauthorized();
    }

    public function test_groups_and_estimates_individual_metric_storage(): void
    {
        $this->get($this->url('getIndividualMetricsSummary'))
            ->assertOk()
            ->assertExactJson([
                ['label' => 'done', 'row_count' => 4, 'estimated_size' => 44],
                ['label' => 'Goal_*_nb_conversions', 'row_count' => 3, 'estimated_size' => 33],
                ['label' => 'metric_*', 'row_count' => 2, 'estimated_size' => 22],
            ]);
    }

    public function test_groups_and_estimates_individual_report_storage(): void
    {
        $storage = new FakeArchiveStorageRepository;
        $this->app->instance(ArchiveStorageRepository::class, $storage);

        $this->get($this->url('getIndividualReportsSummary'))
            ->assertOk()
            ->assertExactJson([
                ['label' => 'Actions_url_*', 'row_count' => 2, 'estimated_size' => 156],
                ['label' => 'Goal_*_conversion_rate', 'row_count' => 1, 'estimated_size' => 38],
            ]);
        self::assertSame(['archive_blob_2025_01'], $storage->columnQueries);
    }

    public function test_reuses_individual_summary_cache_unless_forced(): void
    {
        $storage = new FakeArchiveStorageRepository;
        $this->app->instance(ArchiveStorageRepository::class, $storage);

        $this->get($this->url('getIndividualMetricsSummary'))->assertOk();
        $this->get($this->url('getIndividualMetricsSummary'))->assertOk();
        self::assertCount(3, $storage->rowQueries);

        $this->get($this->url('getIndividualMetricsSummary').'&forceCache=1')->assertOk();
        self::assertCount(6, $storage->rowQueries);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=DBStats.{$method}&format=json&token_auth=super-token";
    }
}

final class FakeDatabaseMetadataProvider implements DatabaseMetadataProvider
{
    public function tablePrefix(): string
    {
        return '';
    }

    public function tableStatuses(): array
    {
        return [
            ['name' => 'site', 'dataLength' => 100, 'indexLength' => 20, 'rows' => 10],
            ['name' => 'user', 'dataLength' => 50, 'indexLength' => 10, 'rows' => 5],
            ['name' => 'log_visit', 'dataLength' => 1000, 'indexLength' => 200, 'rows' => 100],
            ['name' => 'log_profiling', 'dataLength' => 20, 'indexLength' => 5, 'rows' => 2],
            ['name' => 'archive_numeric_2025_01', 'dataLength' => 30, 'indexLength' => 3, 'rows' => 3],
            ['name' => 'archive_numeric_2025_02', 'dataLength' => 40, 'indexLength' => 4, 'rows' => 4],
            ['name' => 'archive_numeric_2026_01', 'dataLength' => 50, 'indexLength' => 5, 'rows' => 5],
            ['name' => 'archive_blob_2025_01', 'dataLength' => 60, 'indexLength' => 6, 'rows' => 6],
            ['name' => 'option', 'dataLength' => 70, 'indexLength' => 7, 'rows' => 7],
        ];
    }

    public function databaseStatus(): array
    {
        return [
            'Uptime' => 1000,
            'Threads' => 3,
            'Questions' => 50,
            'Slow queries' => 2,
            'Flush tables' => 4,
            'Open tables' => 8,
            'Opens' => 'unavailable',
            'Queries per second avg' => 'unavailable',
        ];
    }
}

final class FakeArchiveStorageRepository implements ArchiveStorageRepository
{
    /** @var list<string> */
    public array $rowQueries = [];

    /** @var list<string> */
    public array $columnQueries = [];

    public function rowsByName(string $table, bool $includeBlobSizes): array
    {
        $this->rowQueries[] = $table;

        return match ($table) {
            'archive_numeric_2025_01' => [
                ['label' => 'done7', 'row_count' => 1, 'blob_size' => 0, 'name_size' => 0],
                ['label' => 'done_period', 'row_count' => 1, 'blob_size' => 0, 'name_size' => 0],
                ['label' => 'Goal_2_nb_conversions', 'row_count' => 1, 'blob_size' => 0, 'name_size' => 0],
            ],
            'archive_numeric_2025_02' => [
                ['label' => 'metric_12', 'row_count' => 2, 'blob_size' => 0, 'name_size' => 0],
                ['label' => 'Goal_-1_nb_conversions', 'row_count' => 2, 'blob_size' => 0, 'name_size' => 0],
            ],
            'archive_numeric_2026_01' => [
                ['label' => 'done9', 'row_count' => 2, 'blob_size' => 0, 'name_size' => 0],
            ],
            'archive_blob_2025_01' => [
                ['label' => 'Actions_url_5', 'row_count' => 2, 'blob_size' => 100, 'name_size' => 30],
                ['label' => 'Goal_3_conversion_rate', 'row_count' => 1, 'blob_size' => 20, 'name_size' => 5],
            ],
            default => [],
        };
    }

    public function columnTypes(string $table): array
    {
        $this->columnQueries[] = $table;

        return ['bigint(20) unsigned', 'int(11)', 'blob', 'varchar(255)'];
    }
}
