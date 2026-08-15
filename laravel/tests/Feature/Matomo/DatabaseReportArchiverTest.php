<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveRecordSet;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\CarbonReportingSubperiodFactory;
use App\Matomo\Archiving\DatabaseReportArchiver;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\Events\ArchiveReportsCompleted;
use App\Matomo\Archiving\Events\ArchiveReportsStarting;
use App\Matomo\Archiving\Events\ArchiveVisitsQueryBuilding;
use App\Matomo\Archiving\SegmentDefinitionValidator;
use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Reporting\CarbonReportingPeriodFactory;
use App\Matomo\Reporting\DatabaseBlobArchiveRepository;
use App\Matomo\Reporting\DatabaseSegmentHashResolver;
use App\Matomo\Sites\DatabaseSiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DatabaseReportArchiverTest extends TestCase
{
    private Connection $connection;

    private Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        config()->set('database.connections.matomo_report_archiver_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_report_archiver_test');

        $this->connection = $databases->connection('matomo_report_archiver_test');
        $this->events = new Dispatcher;
        $schema = $this->connection->getSchemaBuilder();
        $schema->create('site', static function (Blueprint $table): void {
            $table->unsignedInteger('idsite')->primary();
            $table->string('timezone');
        });
        $schema->create('option', static function (Blueprint $table): void {
            $table->string('option_name')->primary();
            $table->text('option_value');
            $table->boolean('autoload')->default(false);
        });
        $schema->create('sequence', static function (Blueprint $table): void {
            $table->string('name', 120)->primary();
            $table->unsignedBigInteger('value');
        });
        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->increments('idvisit');
            $table->unsignedInteger('idsite');
            $table->string('idvisitor');
            $table->string('config_id');
            $table->string('user_id')->nullable();
            $table->dateTime('visit_last_action_time');
            $table->unsignedInteger('visit_total_actions');
            $table->unsignedInteger('visit_total_time');
            $table->boolean('visit_goal_converted');
            $table->string('location_country', 3)->nullable();
        });
        $this->connection->table('site')->insert([
            ['idsite' => 1, 'timezone' => 'Pacific/Auckland'],
            ['idsite' => 2, 'timezone' => 'UTC'],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_writes_core_and_extension_records_with_matomo_archive_contracts(): void
    {
        $this->insertVisits();
        $started = 0;
        $completed = 0;
        $collected = 0;
        $this->events->listen(ArchiveReportsStarting::class, static function () use (&$started): void {
            $started++;
        });
        $this->events->listen(ArchiveReportsCollecting::class, static function (
            ArchiveReportsCollecting $event,
        ) use (&$collected): void {
            $collected++;
            $event->records->addNumeric('Example_metric', 7.25);
            $event->records->addBlob(
                'Example_report',
                serialize([[['label' => 'row', 'nb_visits' => 2], []]]),
            );
        });
        $this->events->listen(ArchiveReportsCompleted::class, static function () use (&$completed): void {
            $completed++;
        });
        $archiver = $this->archiver();
        $request = new ArchiveReportRequest(1, 'day', '2026-08-15');

        $result = $archiver->archive($request);

        $this->assertSame([1], $result->archiveIds);
        $this->assertSame(2, $result->visits);
        $this->assertFalse($result->cached);
        $this->assertSame(1, $started);
        $this->assertSame(1, $completed);
        $this->assertSame(1, $collected);
        $this->assertSame(1, (int) $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', 1)
            ->where('name', 'done')
            ->value('value'));
        $this->assertSame([
            'nb_uniq_visitors' => 2.0,
            'nb_uniq_fingerprints' => 1.0,
            'nb_visits' => 2.0,
            'nb_actions' => 4.0,
            'max_actions' => 3.0,
            'sum_visit_length' => 80.0,
            'bounce_count' => 1.0,
            'nb_visits_converted' => 1.0,
            'nb_users' => 1.0,
            'Example_metric' => 7.25,
        ], $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', 1)
            ->whereNot('name', 'done')
            ->orderBy('rowid')
            ->pluck('value', 'name')
            ->all());
        $this->assertSame(1, (int) $this->connection
            ->table('sequence')
            ->where('name', 'matomo_archive_numeric_2026_08')
            ->value('value'));
        $period = (new CarbonReportingPeriodFactory)->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $rows = (new DatabaseBlobArchiveRepository($this->connection))->rows(
            [1],
            [$period],
            '',
            'Example_report',
        );
        $this->assertSame('row', $rows[1]['2026-08-15,2026-08-15'][0]['columns']['label']);

        $cached = $archiver->archive($request);

        $this->assertSame([1], $cached->archiveIds);
        $this->assertSame(2, $cached->visits);
        $this->assertTrue($cached->cached);
        $this->assertSame(2, $started);
        $this->assertSame(2, $completed);
        $this->assertSame(1, $collected);
    }

    public function test_expires_current_archives_and_keeps_completed_archives(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00 UTC');
        $this->insertVisits();
        $this->connection->table('option')->insert([
            'option_name' => 'todayArchiveTimeToLive',
            'option_value' => '60',
            'autoload' => 1,
        ]);
        $archiver = $this->archiver();
        $today = new ArchiveReportRequest(1, 'day', '2026-08-15');
        $yesterday = new ArchiveReportRequest(1, 'day', '2026-08-14');
        $firstToday = $archiver->archive($today);
        $firstYesterday = $archiver->archive($yesterday);
        CarbonImmutable::setTestNow('2026-08-15 10:01:01 UTC');

        $secondToday = $archiver->archive($today);
        $secondYesterday = $archiver->archive($yesterday);

        $this->assertSame([1], $firstToday->archiveIds);
        $this->assertSame([2], $firstYesterday->archiveIds);
        $this->assertSame([3], $secondToday->archiveIds);
        $this->assertFalse($secondToday->cached);
        $this->assertSame([2], $secondYesterday->archiveIds);
        $this->assertTrue($secondYesterday->cached);
    }

    public function test_force_and_partial_plugin_archives_create_compatible_done_flags(): void
    {
        $this->insertVisits();
        $archiver = $this->archiver();
        $request = new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'ExamplePlugin',
            reports: ['ExamplePlugin.getExampleReport'],
        );

        $first = $archiver->archive($request);
        $second = $archiver->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'ExamplePlugin',
            reports: ['ExamplePlugin.getExampleReport'],
            force: true,
        ));

        $this->assertSame([1], $first->archiveIds);
        $this->assertSame([2], $second->archiveIds);
        $this->assertSame([5.0, 5.0], $this->connection
            ->table('archive_numeric_2026_08')
            ->where('name', 'done.ExamplePlugin')
            ->orderBy('idarchive')
            ->pluck('value')
            ->all());
    }

    public function test_segment_query_must_be_handled_and_can_apply_bound_filters(): void
    {
        $this->insertVisits();
        $request = new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: 'countryCode==nz',
        );

        try {
            $this->archiver()->archive($request);
            $this->fail('An unsupported segment must not create a successful archive.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('no segment handler supports it', $invalidArgumentException->getMessage());
        }

        $hash = md5('countryCode==nz');
        $this->assertSame(2, (int) $this->connection
            ->table('archive_numeric_2026_08')
            ->where('name', 'done'.$hash)
            ->value('value'));
        $this->events->listen(ArchiveVisitsQueryBuilding::class, static function (
            ArchiveVisitsQueryBuilding $event,
        ): void {
            if ($event->request->segment === 'countryCode==nz') {
                $event->query->where('location_country', 'nz');
                $event->segmentApplied = true;
            }
        });

        $result = $this->archiver()->archive($request);

        $this->assertSame([2], $result->archiveIds);
        $this->assertSame(1, $result->visits);
        $this->assertSame(1, (int) $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', 2)
            ->where('name', 'done'.$hash)
            ->value('value'));
    }

    public function test_extension_failure_leaves_an_error_marker_and_validation_rejects_bad_inputs(): void
    {
        $this->insertVisits();
        $this->events->listen(ArchiveReportsCollecting::class, static function (): never {
            throw new RuntimeException('Plugin aggregation failed.');
        });

        try {
            $this->archiver()->archive(new ArchiveReportRequest(1, 'day', '2026-08-15'));
            $this->fail('A failed plugin aggregation must be rethrown.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('Plugin aggregation failed.', $runtimeException->getMessage());
        }

        $this->assertSame(2, (int) $this->connection
            ->table('archive_numeric_2026_08')
            ->where('name', 'done')
            ->value('value'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');
        $this->archiver()->archive(new ArchiveReportRequest(99, 'day', '2026-08-15'));
    }

    public function test_record_set_rejects_reserved_names_and_non_finite_values(): void
    {
        $records = new ArchiveRecordSet;

        $this->expectException(InvalidArgumentException::class);
        $records->addNumeric('done.Attacker', 1);
    }

    public function test_record_set_rejects_non_finite_values(): void
    {
        $records = new ArchiveRecordSet;

        $this->expectException(InvalidArgumentException::class);
        $records->addNumeric('Example_metric', INF);
    }

    public function test_aggregates_parent_metrics_from_child_archives_and_computes_exact_uniques(): void
    {
        $this->insertVisits();
        $this->connection->table('log_visit')->insert(
            $this->visit(1, '2026-08-16 00:00:00', 'visitor-a', 'config-a', 'alice', 2, 10, 0, 'nz'),
        );

        $result = $this->archiver()->archive(new ArchiveReportRequest(1, 'week', '2026-08-12'));

        $this->assertSame([1], $result->archiveIds);
        $this->assertSame(5, $result->visits);
        $this->assertFalse($result->cached);
        $expected = [
            'nb_uniq_visitors' => 4.0,
            'nb_visits' => 5.0,
            'nb_actions' => 13.0,
            'max_actions' => 5.0,
            'sum_visit_length' => 180.0,
            'bounce_count' => 1.0,
            'nb_visits_converted' => 1.0,
            'nb_users' => 1.0,
            'sum_daily_nb_uniq_visitors' => 5.0,
            'sum_daily_nb_users' => 2.0,
        ];
        $actual = $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', 1)
            ->whereNot('name', 'done')
            ->pluck('value', 'name')
            ->all();
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(8, $this->connection
            ->table('archive_numeric_2026_08')
            ->where('name', 'done')
            ->count());

        $cached = $this->archiver()->archive(new ArchiveReportRequest(1, 'week', '2026-08-12'));

        $this->assertSame([1], $cached->archiveIds);
        $this->assertTrue($cached->cached);
    }

    private function archiver(): DatabaseReportArchiver
    {
        return new DatabaseReportArchiver(
            connection: $this->connection,
            periods: new CarbonReportingPeriodFactory,
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
            options: new DatabaseOptionRepository($this->connection),
            segmentValidator: new SegmentDefinitionValidator,
            events: $this->events,
        );
    }

    private function insertVisits(): void
    {
        $this->connection->table('log_visit')->insert([
            $this->visit(1, '2026-08-14 12:00:00', 'visitor-a', 'config-a', 'alice', 1, 20, 0, 'nz'),
            $this->visit(1, '2026-08-15 11:59:59', 'visitor-b', 'config-a', null, 3, 60, 1, 'au'),
            $this->visit(1, '2026-08-14 11:59:59', 'outside-a', 'config-b', null, 5, 80, 0, 'nz'),
            $this->visit(1, '2026-08-15 12:00:00', 'outside-b', 'config-c', null, 2, 10, 0, 'nz'),
            $this->visit(2, '2026-08-15 00:00:00', 'other-site', 'config-d', null, 2, 10, 0, 'nz'),
        ]);
    }

    /** @return array<string, int|string|null> */
    private function visit(
        int $siteId,
        string $lastAction,
        string $visitor,
        string $configuration,
        ?string $userId,
        int $actions,
        int $duration,
        int $converted,
        string $country,
    ): array {
        return [
            'idsite' => $siteId,
            'idvisitor' => $visitor,
            'config_id' => $configuration,
            'user_id' => $userId,
            'visit_last_action_time' => $lastAction,
            'visit_total_actions' => $actions,
            'visit_total_time' => $duration,
            'visit_goal_converted' => $converted,
            'location_country' => $country,
        ];
    }
}
