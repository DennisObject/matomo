<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo\Reporting;

use App\Matomo\Reporting\DatabaseBlobArchiveRepository;
use App\Matomo\Reporting\ReportingPeriod;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Tests\TestCase;

class DatabaseBlobArchiveRepositoryTest extends TestCase
{
    public function test_reads_and_safely_decodes_the_latest_plugin_blob(): void
    {
        $connection = $this->archiveConnection();
        $this->createTables($connection->getSchemaBuilder());
        $connection->table('archive_numeric_2026_08')->insert([
            $this->numericRow(10, 'done', 1, '2026-08-15 00:00:00'),
            $this->numericRow(11, 'done.VisitTime', 5, '2026-08-15 01:00:00'),
        ]);
        $connection->table('archive_blob_2026_08')->insert([
            $this->blobRow(10, 'VisitTime_localTime', $this->blob([['label' => 1, 'nb_visits' => 2]]), '2026-08-15 00:00:00'),
            $this->blobRow(11, 'VisitTime_localTime', $this->blob([['label' => 2, 'nb_visits' => 3]]), '2026-08-15 01:00:00'),
        ]);

        $repository = new DatabaseBlobArchiveRepository($connection);
        $this->assertSame([
            3 => ['2026-08-14,2026-08-14' => [[
                'columns' => ['label' => 2, 'nb_visits' => 3],
                'metadata' => ['segment' => 'visitLocalHour==2'],
            ]]],
        ], $repository->rows(
            [3],
            [$this->day()],
            '',
            'VisitTime_localTime',
        ));
        $archive = $repository->archives([3], [$this->day()], '', 'VisitTime_localTime');
        $this->assertSame('2026-08-15 01:00:00', $archive[3]['2026-08-14,2026-08-14']->archivedAt);
    }

    public function test_rejects_serialized_objects_and_missing_tables(): void
    {
        $connection = $this->archiveConnection();
        $repository = new DatabaseBlobArchiveRepository($connection);
        $this->assertSame([], $repository->rows([3], [$this->day()], '', 'VisitTime_localTime'));
        $this->createTables($connection->getSchemaBuilder());
        $connection->table('archive_numeric_2026_08')->insert(
            $this->numericRow(20, 'done', 1, '2026-08-15 00:00:00'),
        );
        $connection->table('archive_blob_2026_08')->insert(
            $this->blobRow(20, 'VisitTime_localTime', $this->compress(serialize([new \stdClass])), '2026-08-15 00:00:00'),
        );

        $this->assertSame([], $repository->rows([3], [$this->day()], '', 'VisitTime_localTime'));
    }

    private function archiveConnection(): Connection
    {
        config()->set('database.connections.matomo_blob_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_blob_test');

        return $databases->connection('matomo_blob_test');
    }

    private function createTables(Builder $schema): void
    {
        $schema->create('archive_numeric_2026_08', function (Blueprint $table): void {
            $this->columns($table);
            $table->double('value');
        });
        $schema->create('archive_blob_2026_08', function (Blueprint $table): void {
            $this->columns($table);
            $table->binary('value');
        });
    }

    private function columns(Blueprint $table): void
    {
        $table->unsignedInteger('idarchive');
        $table->string('name');
        $table->unsignedInteger('idsite');
        $table->date('date1');
        $table->date('date2');
        $table->unsignedTinyInteger('period');
        $table->dateTime('ts_archived');
        $table->primary(['idarchive', 'name']);
    }

    /** @return array<string, float|int|string> */
    private function numericRow(int $idArchive, string $name, int $value, string $archivedAt): array
    {
        return [...$this->baseRow($idArchive, $name, $archivedAt), 'value' => $value];
    }

    /** @return array<string, int|string> */
    private function blobRow(int $idArchive, string $name, string $value, string $archivedAt): array
    {
        return [...$this->baseRow($idArchive, $name, $archivedAt), 'value' => $value];
    }

    /** @return array<string, int|string> */
    private function baseRow(int $idArchive, string $name, string $archivedAt): array
    {
        return [
            'idarchive' => $idArchive,
            'name' => $name,
            'idsite' => 3,
            'date1' => '2026-08-14',
            'date2' => '2026-08-14',
            'period' => 1,
            'ts_archived' => $archivedAt,
        ];
    }

    /** @param list<array<string, int|string>> $rows */
    private function blob(array $rows): string
    {
        $payload = array_map(static fn (array $columns): array => [
            0 => $columns,
            1 => ['segment' => 'visitLocalHour=='.$columns['label']],
            3 => null,
        ], $rows);

        return $this->compress(serialize($payload));
    }

    private function compress(string $value): string
    {
        $compressed = gzcompress($value);

        if (! is_string($compressed)) {
            throw new \RuntimeException('The test archive payload could not be compressed.');
        }

        return $compressed;
    }

    private function day(): ReportingPeriod
    {
        return new ReportingPeriod('day', 1, '2026-08-14', '2026-08-14', '2026-08-14');
    }
}
