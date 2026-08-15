<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\ArchiveReportResult;
use App\Matomo\Archiving\DatabaseCronArchiveRunner;
use App\Matomo\Archiving\Events\AutoArchiveSegmentsCollecting;
use App\Matomo\Archiving\Events\CronArchiveSitesSelecting;
use App\Matomo\Archiving\Events\CronArchivingCompleted;
use App\Matomo\Archiving\ReportArchiver;
use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Scheduling\DatabaseScheduledTaskLock;
use App\Matomo\Scheduling\ScheduledTaskRunner;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

class DatabaseCronArchiveRunnerTest extends TestCase
{
    private Connection $connection;

    private Dispatcher $events;

    private DatabaseOptionRepository $options;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        config()->set('database.connections.matomo_cron_archive_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_cron_archive_test');

        $this->connection = $databases->connection('matomo_cron_archive_test');
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('option', static function (Blueprint $table): void {
            $table->string('option_name')->primary();
            $table->text('option_value');
            $table->boolean('autoload')->default(false);
        });
        $schema->create('locks', static function (Blueprint $table): void {
            $table->string('key', 70)->primary();
            $table->string('value')->nullable();
            $table->unsignedBigInteger('expiry_time');
        });
        $schema->create('archive_invalidations', static function (Blueprint $table): void {
            $table->id('idinvalidation');
            $table->unsignedInteger('idarchive')->nullable();
            $table->string('name');
            $table->string('report')->nullable();
            $table->unsignedInteger('idsite');
            $table->date('date1');
            $table->date('date2');
            $table->unsignedTinyInteger('period');
            $table->dateTime('ts_invalidated')->nullable();
            $table->dateTime('ts_started')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('processing_host', 100)->nullable();
            $table->string('process_id', 15)->nullable();
        });
        $schema->create('segment', static function (Blueprint $table): void {
            $table->id('idsegment');
            $table->text('definition');
            $table->string('hash', 32);
            $table->boolean('deleted')->default(false);
            $table->boolean('auto_archive')->default(false);
            $table->unsignedInteger('enable_only_idsite')->default(0);
        });
        $this->events = new Dispatcher;
        $this->options = new DatabaseOptionRepository($this->connection);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_archives_recent_periods_and_automatic_segments_then_runs_tasks(): void
    {
        $sites = $this->sites([1], [1 => 'Pacific/Auckland']);
        $requests = [];
        $archiver = $this->createMock(ReportArchiver::class);
        $archiver->method('archive')->willReturnCallback(
            static function (ArchiveReportRequest $request) use (&$requests): ArchiveReportResult {
                $requests[] = $request;

                return new ArchiveReportResult([count($requests)], 3, false);
            },
        );
        $tasks = $this->createMock(ScheduledTaskRunner::class);
        $tasks->expects($this->once())->method('run')->willReturn([
            ['task' => 'CoreAdmin.cleanup', 'output' => 'Time elapsed: 0.001s'],
        ]);
        $this->connection->table('segment')->insert([
            'definition' => 'browserCode==FF',
            'hash' => md5('browserCode==FF'),
            'deleted' => 0,
            'auto_archive' => 1,
            'enable_only_idsite' => 1,
        ]);
        $this->events->listen(
            CronArchiveSitesSelecting::class,
            static function (CronArchiveSitesSelecting $event): void {
                $event->siteIds = [1, 999];
            },
        );
        $this->events->listen(
            AutoArchiveSegmentsCollecting::class,
            static function (AutoArchiveSegmentsCollecting $event): void {
                if ($event->siteId === 1) {
                    $event->definitions[] = 'deviceType==smartphone';
                }
            },
        );
        $completed = [];
        $this->events->listen(
            CronArchivingCompleted::class,
            static function (CronArchivingCompleted $event) use (&$completed): void {
                $completed[] = $event->result;
            },
        );

        $result = $this->runner(
            $sites,
            $archiver,
            $tasks,
            ['countryCode==nz'],
            ['day', 'week'],
        )->run();

        $this->assertCount(12, $requests);
        $this->assertSame('2026-08-15', $requests[0]->date);
        $this->assertTrue($requests[0]->force);
        $this->assertSame('2026-08-16', $requests[1]->date);
        $this->assertFalse($requests[1]->force);
        $this->assertSame(
            [null, 'countryCode==nz', 'browserCode==FF', 'deviceType==smartphone'],
            array_values(array_unique(array_map(
                static fn (ArchiveReportRequest $request): ?string => $request->segment,
                $requests,
            ), SORT_REGULAR)),
        );
        $this->assertSame(12, $result->archives);
        $this->assertSame(0, $result->errors);
        $this->assertStringContainsString('Scheduled task CoreAdmin.cleanup', implode("\n", $result->lines));
        $this->assertSame('1786795200', $this->options->value('LastFullArchivingStartTime'));
        $this->assertSame('1786795200', $this->options->value('LastCompletedFullArchiving'));
        $this->assertCount(1, $completed);
        $this->assertSame(0, $this->connection->table('locks')->count());
    }

    public function test_processes_queued_segment_report_and_removes_the_invalidation(): void
    {
        $definition = 'countryCode==fr';
        $this->connection->table('segment')->insert([
            'definition' => $definition,
            'hash' => md5($definition),
            'deleted' => 0,
            'auto_archive' => 1,
            'enable_only_idsite' => 0,
        ]);
        $this->connection->table('archive_invalidations')->insert([
            'name' => 'done'.md5($definition).'.VisitsSummary',
            'report' => 'getVisits',
            'idsite' => 7,
            'date1' => '2026-08-01',
            'date2' => '2026-08-14',
            'period' => 5,
            'ts_started' => '2026-08-13 00:00:00',
            'status' => 1,
        ]);
        $request = null;
        $archiver = $this->createMock(ReportArchiver::class);
        $archiver->expects($this->once())->method('archive')->willReturnCallback(
            static function (ArchiveReportRequest $archiveRequest) use (&$request): ArchiveReportResult {
                $request = $archiveRequest;

                return new ArchiveReportResult([77], 9, false);
            },
        );

        $result = $this->runner(
            $this->sites([], []),
            $archiver,
            $this->tasks(),
            [],
            ['day', 'week', 'month', 'year', 'range'],
        )->run();

        $this->assertEquals(new ArchiveReportRequest(
            siteId: 7,
            period: 'range',
            date: '2026-08-01,2026-08-14',
            segment: $definition,
            plugin: 'VisitsSummary',
            reports: ['getVisits'],
            force: true,
        ), $request);
        $this->assertSame(1, $result->archives);
        $this->assertSame(0, $result->errors);
        $this->assertSame(0, $this->connection->table('archive_invalidations')->count());
    }

    public function test_releases_failed_invalidation_and_does_not_mark_run_complete(): void
    {
        $this->connection->table('archive_invalidations')->insert([
            'name' => 'done',
            'idsite' => 7,
            'date1' => '2026-08-15',
            'date2' => '2026-08-15',
            'period' => 1,
            'status' => 0,
        ]);
        $archiver = $this->createMock(ReportArchiver::class);
        $archiver->expects($this->once())->method('archive')->willThrowException(
            new RuntimeException('database unavailable'),
        );

        $result = $this->runner(
            $this->sites([], []),
            $archiver,
            $this->tasks(),
            [],
            ['day'],
        )->run();

        $this->assertSame(0, $result->archives);
        $this->assertSame(1, $result->errors);
        $this->assertNull($this->options->value('LastCompletedFullArchiving'));
        $this->assertSame(0, $this->connection->table('archive_invalidations')->value('status'));
        $this->assertNull($this->connection->table('archive_invalidations')->value('ts_started'));
        $this->assertSame(0, $this->connection->table('locks')->count());
    }

    public function test_active_lock_skips_the_run_without_side_effects(): void
    {
        $this->connection->table('locks')->insert([
            'key' => 'ScheduledTaskCronArchive',
            'value' => 'other-runner',
            'expiry_time' => CarbonImmutable::now('UTC')->addHour()->getTimestamp(),
        ]);
        $archiver = $this->createMock(ReportArchiver::class);
        $archiver->expects($this->never())->method('archive');
        $tasks = $this->createMock(ScheduledTaskRunner::class);
        $tasks->expects($this->never())->method('run');

        $result = $this->runner(
            $this->sites([1], [1 => 'UTC']),
            $archiver,
            $tasks,
            [],
            ['day'],
        )->run();

        $this->assertSame(0, $result->archives);
        $this->assertSame(0, $result->errors);
        $this->assertSame(
            ['Archiving skipped because another archiving run is active.'],
            $result->lines,
        );
        $this->assertNull($this->options->value('LastFullArchivingStartTime'));
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $timezones
     */
    private function sites(array $ids, array $timezones): SiteRepository
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('allIds')->willReturn($ids);
        $sites->method('timezone')->willReturnCallback(
            static fn (int $siteId): ?string => $timezones[$siteId] ?? null,
        );

        return $sites;
    }

    private function tasks(): ScheduledTaskRunner
    {
        $tasks = $this->createStub(ScheduledTaskRunner::class);
        $tasks->method('run')->willReturn([]);

        return $tasks;
    }

    /**
     * @param  list<string>  $configuredSegments
     * @param  list<string>  $periods
     */
    private function runner(
        SiteRepository $sites,
        ReportArchiver $archiver,
        ScheduledTaskRunner $tasks,
        array $configuredSegments,
        array $periods,
    ): DatabaseCronArchiveRunner {
        return new DatabaseCronArchiveRunner(
            connection: $this->connection,
            sites: $sites,
            archiver: $archiver,
            scheduledTasks: $tasks,
            options: $this->options,
            lock: new DatabaseScheduledTaskLock($this->connection),
            events: $this->events,
            logger: new NullLogger,
            configuredAutoArchiveSegments: $configuredSegments,
            enabledReportingPeriods: $periods,
        );
    }
}
