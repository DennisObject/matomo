<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo\Reporting;

use App\Matomo\Reporting\DatabaseVisitsSummaryArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Tests\TestCase;

class DatabaseVisitsSummaryArchiveRepositoryTest extends TestCase
{
    public function test_reads_the_latest_full_and_partial_archives_with_the_matomo_prefix(): void
    {
        $connection = $this->archiveConnection();
        $this->createArchiveTable($connection->getSchemaBuilder());
        $connection->table('archive_numeric_2026_08')->insert([
            $this->archiveRow(10, 'done', 1, '2026-08-15 00:00:00'),
            $this->archiveRow(10, 'nb_visits', 1, '2026-08-15 00:00:00'),
            $this->archiveRow(10, 'nb_actions', 2, '2026-08-15 00:00:00'),
            $this->archiveRow(11, 'done.VisitsSummary', 1, '2026-08-15 01:00:00'),
            $this->archiveRow(11, 'nb_visits', 3, '2026-08-15 01:00:00'),
            $this->archiveRow(11, 'nb_actions', 4, '2026-08-15 01:00:00'),
            $this->archiveRow(12, 'done.VisitsSummary', 5, '2026-08-15 02:00:00'),
            $this->archiveRow(12, 'nb_actions', 7, '2026-08-15 02:00:00'),
        ]);
        $repository = new DatabaseVisitsSummaryArchiveRepository($connection);

        $metrics = $repository->metrics(
            [3],
            [$this->day()],
            '',
            ['nb_visits', 'nb_actions'],
        );

        $this->assertEquals([
            3 => [
                '2026-08-14,2026-08-14' => [
                    'nb_visits' => 3,
                    'nb_actions' => 7,
                ],
            ],
        ], $metrics);
    }

    public function test_uses_the_segment_hash_and_returns_empty_when_the_table_is_missing(): void
    {
        $connection = $this->archiveConnection();
        $repository = new DatabaseVisitsSummaryArchiveRepository($connection);

        $this->assertSame([], $repository->metrics(
            [3],
            [$this->day()],
            md5('countryCode==NZ'),
            ['nb_visits'],
        ));

        $this->createArchiveTable($connection->getSchemaBuilder());
        $hash = md5('countryCode==NZ');
        $connection->table('archive_numeric_2026_08')->insert([
            $this->archiveRow(20, 'done'.$hash, 1, '2026-08-15 00:00:00'),
            $this->archiveRow(20, 'nb_visits', 2.25, '2026-08-15 00:00:00'),
        ]);

        $this->assertSame([
            3 => ['2026-08-14,2026-08-14' => ['nb_visits' => 2.25]],
        ], $repository->metrics([3], [$this->day()], $hash, ['nb_visits']));
    }

    public function test_reads_metrics_from_a_named_plugin_archive(): void
    {
        $connection = $this->archiveConnection();
        $this->createArchiveTable($connection->getSchemaBuilder());
        $connection->table('archive_numeric_2026_08')->insert([
            $this->archiveRow(30, 'done.UserCountry', 1, '2026-08-15 00:00:00'),
            $this->archiveRow(30, 'UserCountry_distinctCountries', 4, '2026-08-15 00:00:00'),
        ]);
        $repository = new DatabaseVisitsSummaryArchiveRepository($connection);

        $this->assertSame([
            3 => ['2026-08-14,2026-08-14' => ['UserCountry_distinctCountries' => 4]],
        ], $repository->pluginMetrics(
            [3],
            [$this->day()],
            '',
            ['UserCountry_distinctCountries'],
            'UserCountry',
        ));
    }

    private function archiveConnection(): Connection
    {
        config()->set('database.connections.matomo_archive_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_archive_test');

        return $databases->connection('matomo_archive_test');
    }

    private function createArchiveTable(Builder $schema): void
    {
        $schema->create('archive_numeric_2026_08', function (Blueprint $table): void {
            $table->unsignedInteger('idarchive');
            $table->string('name');
            $table->unsignedInteger('idsite');
            $table->date('date1');
            $table->date('date2');
            $table->unsignedTinyInteger('period');
            $table->dateTime('ts_archived');
            $table->double('value');
            $table->primary(['idarchive', 'name']);
        });
    }

    /**
     * @return array<string, float|int|string>
     */
    private function archiveRow(
        int $idArchive,
        string $name,
        float|int $value,
        string $archivedAt,
    ): array {
        return [
            'idarchive' => $idArchive,
            'name' => $name,
            'idsite' => 3,
            'date1' => '2026-08-14',
            'date2' => '2026-08-14',
            'period' => 1,
            'ts_archived' => $archivedAt,
            'value' => $value,
        ];
    }

    private function day(): ReportingPeriod
    {
        return new ReportingPeriod('day', 1, '2026-08-14', '2026-08-14', '2026-08-14');
    }
}
