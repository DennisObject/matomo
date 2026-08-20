<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\DatabaseArchiveInvalidationManager;
use App\Matomo\Archiving\Events\ArchiveSitesSelecting;
use App\Matomo\Archiving\Events\AutoArchiveSegmentsCollecting;
use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Reporting\CarbonReportingPeriodFactory;
use App\Matomo\Reporting\DatabaseSegmentHashResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Tests\TestCase;

class DatabaseArchiveInvalidationManagerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        config()->set('database.connections.matomo_archive_invalidation_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_archive_invalidation_test');

        $this->connection = $databases->connection('matomo_archive_invalidation_test');
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('option', static function (Blueprint $table): void {
            $table->string('option_name')->primary();
            $table->text('option_value');
            $table->boolean('autoload')->default(false);
        });
        $schema->create('site', static function (Blueprint $table): void {
            $table->integer('idsite')->primary();
            $table->dateTime('ts_created');
        });
        $schema->create('segment', static function (Blueprint $table): void {
            $table->increments('idsegment');
            $table->text('definition');
            $table->string('hash')->nullable();
            $table->integer('enable_only_idsite')->nullable();
            $table->boolean('auto_archive')->default(false);
            $table->boolean('deleted')->default(false);
        });
        $schema->create('archive_invalidations', static function (Blueprint $table): void {
            $table->increments('idinvalidation');
            $table->integer('idarchive')->nullable();
            $table->string('name');
            $table->string('report')->nullable();
            $table->integer('idsite');
            $table->date('date1');
            $table->date('date2');
            $table->integer('period');
            $table->dateTime('ts_invalidated')->nullable();
            $table->dateTime('ts_started')->nullable();
            $table->integer('status')->default(0);
            $table->string('processing_host')->nullable();
            $table->string('process_id')->nullable();
        });
        $this->archiveTable('archive_numeric_2026_08');
        $this->archiveTable('archive_numeric_2026_01');
        $this->connection->table('site')->insert([
            'idsite' => 1,
            'ts_created' => '2020-01-01 00:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_invalidates_matching_archives_ranges_and_queues_parent_periods(): void
    {
        $segmentHash = md5('countryCode==fr');
        $this->connection->table('archive_numeric_2026_08')->insert([
            $this->archive(1, 1, '2026-08-15', '2026-08-15', 'done', 1),
            $this->archive(2, 1, '2026-08-15', '2026-08-15', 'done'.$segmentHash, 1),
            $this->archive(3, 1, '2026-08-15', '2026-08-15', 'done', 5),
            $this->archive(4, 2, '2026-08-10', '2026-08-16', 'done', 2),
            $this->archive(5, 3, '2026-08-01', '2026-08-31', 'done', 1),
            $this->archive(6, 5, '2026-08-01', '2026-08-31', 'done', 1),
        ]);
        $this->connection->table('archive_numeric_2026_01')->insert(
            $this->archive(7, 4, '2026-01-01', '2026-12-31', 'done', 1),
        );
        $this->connection->table('option')->insert([
            'option_name' => 'x_report_to_invalidate_1_2026-08-15_99',
            'option_value' => '1',
            'autoload' => 0,
        ]);

        $logs = $this->manager()->invalidate([1], ['2026-08-15'], null, null, false, false);

        $values = $this->connection
            ->table('archive_numeric_2026_08')
            ->orderBy('idarchive')
            ->pluck('value')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        $this->assertSame([4, 4, 5, 6, 4, 4], $values);
        $this->assertSame(4, $this->connection->table('archive_invalidations')->count());
        $this->assertSame(
            [1, 2, 3, 4],
            $this->connection->table('archive_invalidations')
                ->orderBy('period')
                ->pluck('period')
                ->map(static fn (mixed $period): int => (int) $period)
                ->all(),
        );
        $this->assertSame(
            ['Success. The following dates were invalidated successfully: 2026-08-15'],
            $logs,
        );
        $this->assertFalse($this->connection->table('option')
            ->where('option_name', 'x_report_to_invalidate_1_2026-08-15_99')->exists());
        $purgeList = $this->connection->table('option')
            ->where('option_name', 'InvalidatedOldReports_DatesWebsiteIds')
            ->value('option_value');
        $this->assertSame(['2026_08', '2026_01'], unserialize((string) $purgeList));
    }

    public function test_limits_a_segment_and_only_forces_nonexistent_ranges_when_requested(): void
    {
        $segment = 'countryCode==fr';
        $segmentHash = md5($segment);
        $this->connection->table('archive_numeric_2026_08')->insert([
            $this->archive(1, 5, '2026-08-01', '2026-08-31', 'done', 1),
            $this->archive(2, 5, '2026-08-01', '2026-08-31', 'done'.$segmentHash, 1),
        ]);

        $this->manager()->invalidate(
            [1],
            ['2026-08-10,2026-08-20'],
            'range',
            $segment,
            false,
            false,
        );

        $this->assertSame(1, (int) $this->connection->table('archive_numeric_2026_08')
            ->where('idarchive', 1)->value('value'));
        $this->assertSame(4, (int) $this->connection->table('archive_numeric_2026_08')
            ->where('idarchive', 2)->value('value'));
        $this->assertSame(0, $this->connection->table('archive_invalidations')->count());

        $this->manager()->invalidate(
            [1],
            ['2026-08-02,2026-08-03'],
            'range',
            'browserCode==FF',
            false,
            false,
        );
        $this->assertSame(0, $this->connection->table('archive_invalidations')->count());

        $this->manager()->invalidate(
            [1],
            ['2026-08-02,2026-08-03'],
            'range',
            'browserCode==FF',
            false,
            true,
        );
        $this->assertSame(1, $this->connection->table('archive_invalidations')->count());
        $this->assertSame('done'.md5('browserCode==FF'), $this->connection
            ->table('archive_invalidations')->value('name'));
    }

    public function test_reports_invalid_and_purged_dates_and_cascades_to_children(): void
    {
        $this->connection->table('option')->insert([
            [
                'option_name' => 'delete_logs_enable',
                'option_value' => '1',
                'autoload' => 0,
            ],
            [
                'option_name' => 'delete_logs_older_than',
                'option_value' => '30',
                'autoload' => 0,
            ],
        ]);

        $logs = $this->manager()->invalidate(
            [1],
            ['2026-01-01', 'not-a-date', '2026-8-15', '2026-02-31', 'today'],
            'day',
            null,
            false,
            false,
        );

        $this->assertCount(3, $logs);
        $this->assertStringContainsString('2026-01-01', $logs[0]);
        $this->assertSame('Success. The following dates were invalidated successfully: 2026-08-15', $logs[1]);
        $this->assertStringContainsString("'not-a-date'", $logs[2]);
        $this->assertStringContainsString("'2026-8-15'", $logs[2]);
        $this->assertStringContainsString("'2026-02-31'", $logs[2]);

        $this->connection->table('option')->whereIn('option_name', [
            'delete_logs_enable',
            'delete_logs_older_than',
        ])->delete();
        $this->connection->table('archive_invalidations')->delete();
        $this->manager()->invalidate([1], ['2026-08-15'], 'month', null, true, false);

        $this->assertSame(39, $this->connection->table('archive_invalidations')->count());

        $this->connection->table('archive_invalidations')->delete();
        $logs = $this->manager()->invalidate([1], ['2026-08-15'], 'month', null, false, false);
        $this->assertSame(
            ['Success. The following dates were invalidated successfully: 2026-08-15'],
            $logs,
        );
    }

    public function test_queues_only_default_and_auto_archive_segment_done_flags(): void
    {
        $autoHash = md5('countryCode==fr');
        $eventHash = md5('browserCode==FF');
        $storedHash = md5('visitCount>3');
        $manualHash = md5('deviceType==desktop');
        $this->connection->table('segment')->insert([
            'definition' => 'visitCount>3',
            'hash' => $storedHash,
            'enable_only_idsite' => 1,
            'auto_archive' => 1,
            'deleted' => 0,
        ]);
        $this->connection->table('archive_numeric_2026_08')->insert([
            $this->archive(1, 1, '2026-08-15', '2026-08-15', 'done', 1),
            $this->archive(2, 1, '2026-08-15', '2026-08-15', 'done'.$autoHash, 1),
            $this->archive(3, 1, '2026-08-15', '2026-08-15', 'done'.$eventHash, 1),
            $this->archive(4, 1, '2026-08-15', '2026-08-15', 'done'.$manualHash, 1),
            $this->archive(5, 1, '2026-08-15', '2026-08-15', 'done'.$autoHash.'.Goals', 1),
            $this->archive(6, 1, '2026-08-15', '2026-08-15', 'done'.$storedHash, 1),
        ]);
        $events = new Dispatcher;
        $events->listen(AutoArchiveSegmentsCollecting::class, static function (
            AutoArchiveSegmentsCollecting $event,
        ): void {
            if ($event->siteId === 1) {
                $event->definitions[] = 'browserCode==FF';
            }
        });

        $this->manager($events, ['countryCode==fr'])
            ->invalidate([1], ['2026-08-15'], null, null, false, false);

        $this->assertSame(
            ['done', 'done'.$autoHash, 'done'.$eventHash, 'done'.$storedHash],
            $this->connection->table('archive_invalidations')
                ->where('period', 1)
                ->orderBy('idinvalidation')
                ->pluck('name')
                ->all(),
        );
        $this->assertSame(7, $this->connection->table('archive_invalidations')->count());
        $this->assertSame(4, $this->connection->table('archive_numeric_2026_08')
            ->where('idarchive', 4)->value('value'));
        $this->assertSame(4, $this->connection->table('archive_numeric_2026_08')
            ->where('idarchive', 5)->value('value'));
    }

    public function test_extensions_can_add_or_remove_sites_before_invalidation(): void
    {
        $this->connection->table('site')->insert([
            'idsite' => 2,
            'ts_created' => '2020-01-01 00:00:00',
        ]);
        $this->connection->table('archive_numeric_2026_08')->insert([
            $this->archive(1, 1, '2026-08-15', '2026-08-15', 'done', 1),
            $this->archive(2, 1, '2026-08-15', '2026-08-15', 'done', 1, 2),
        ]);
        $events = new Dispatcher;
        $events->listen(ArchiveSitesSelecting::class, static function (ArchiveSitesSelecting $event): void {
            $event->siteIds = [2, 99, -1, 2];
        });

        $this->manager($events)->invalidate([1], ['2026-08-15'], null, null, false, false);

        $this->assertSame(1, (int) $this->connection->table('archive_numeric_2026_08')
            ->where('idarchive', 1)->value('value'));
        $this->assertSame(4, (int) $this->connection->table('archive_numeric_2026_08')
            ->where('idarchive', 2)->value('value'));
        $this->assertSame([2], $this->connection->table('archive_invalidations')
            ->distinct()->pluck('idsite')->all());
    }

    public function test_accepts_relative_ranges_and_rejects_malformed_segments(): void
    {
        $logs = $this->manager()->invalidate([1], ['last7'], 'range', null, false, true);

        $this->assertSame(
            ['Success. The following dates were invalidated successfully: 2026-08-09,2026-08-15'],
            $logs,
        );
        $this->assertSame('2026-08-09', (string) $this->connection
            ->table('archive_invalidations')->value('date1'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("The segment condition 'not-a-segment' is not valid.");

        $this->manager()->invalidate(
            [1],
            ['2026-08-15'],
            'day',
            'not-a-segment',
            false,
            false,
        );
    }

    /** @param list<string> $configuredAutoArchiveSegments */
    private function manager(
        ?Dispatcher $events = null,
        array $configuredAutoArchiveSegments = [],
    ): DatabaseArchiveInvalidationManager {
        return new DatabaseArchiveInvalidationManager(
            connection: $this->connection,
            periods: new CarbonReportingPeriodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            options: new DatabaseOptionRepository($this->connection),
            events: $events ?? new Dispatcher,
            enabledReportingPeriods: ['day', 'week', 'month', 'year', 'range'],
            configuredAutoArchiveSegments: $configuredAutoArchiveSegments,
        );
    }

    private function archiveTable(string $name): void
    {
        $this->connection->getSchemaBuilder()->create($name, static function (Blueprint $table): void {
            $table->integer('idarchive');
            $table->integer('idsite');
            $table->integer('period');
            $table->date('date1');
            $table->date('date2');
            $table->string('name');
            $table->integer('value');
        });
    }

    /** @return array{idarchive: int, idsite: int, period: int, date1: string, date2: string, name: string, value: int} */
    private function archive(
        int $idArchive,
        int $period,
        string $date1,
        string $date2,
        string $name,
        int $value,
        int $siteId = 1,
    ): array {
        return [
            'idarchive' => $idArchive,
            'idsite' => $siteId,
            'period' => $period,
            'date1' => $date1,
            'date2' => $date2,
            'name' => $name,
            'value' => $value,
        ];
    }
}
