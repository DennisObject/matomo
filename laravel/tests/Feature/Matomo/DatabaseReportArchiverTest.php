<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ArchiveRecordSet;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\BuiltInVisitSegmentApplicator;
use App\Matomo\Archiving\CarbonReportingSubperiodFactory;
use App\Matomo\Archiving\DatabaseReportArchiver;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\Events\ArchiveReportsCompleted;
use App\Matomo\Archiving\Events\ArchiveReportsStarting;
use App\Matomo\Archiving\Events\ArchiveVisitsQueryBuilding;
use App\Matomo\Archiving\SegmentDefinitionValidator;
use App\Matomo\Archiving\SegmentExpressionParser;
use App\Matomo\Geolocation\CountryMetadataProvider;
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
            $table->unsignedInteger('visit_entry_idaction_url')->nullable();
            $table->unsignedInteger('visit_entry_idaction_name')->nullable();
            $table->unsignedInteger('visit_exit_idaction_url')->nullable();
            $table->unsignedInteger('visit_exit_idaction_name')->nullable();
            $table->dateTime('visit_first_action_time')->nullable();
            $table->dateTime('visit_last_action_time');
            $table->unsignedInteger('visit_total_actions');
            $table->unsignedInteger('visit_total_interactions')->nullable();
            $table->unsignedInteger('visit_total_searches')->nullable();
            $table->unsignedInteger('visit_total_events')->nullable();
            $table->unsignedInteger('visit_total_time');
            $table->boolean('visit_goal_converted');
            $table->unsignedTinyInteger('visitor_returning')->nullable();
            $table->unsignedTinyInteger('visit_goal_buyer')->nullable();
            $table->unsignedInteger('visitor_seconds_since_first')->nullable();
            $table->unsignedInteger('visitor_seconds_since_last')->nullable();
            $table->unsignedInteger('visitor_seconds_since_order')->nullable();
            $table->unsignedTinyInteger('config_device_type')->nullable();
            $table->string('referer_name')->nullable();
            $table->string('referer_url')->nullable();
            $table->binary('location_ip')->nullable();
            $table->string('location_country', 3)->nullable();
        });
        $schema->create('log_action', static function (Blueprint $table): void {
            $table->unsignedInteger('idaction')->primary();
            $table->text('name');
            $table->unsignedTinyInteger('type');
            $table->unsignedTinyInteger('url_prefix')->nullable();
        });
        $schema->create('log_link_visit_action', static function (Blueprint $table): void {
            $table->increments('idlink_va');
            $table->unsignedInteger('idsite');
            $table->unsignedInteger('idvisit');
            $table->unsignedInteger('idaction_url')->nullable();
            $table->unsignedInteger('idaction_name')->nullable();
            $table->unsignedInteger('idaction_event_category')->nullable();
            $table->unsignedInteger('idaction_event_action')->nullable();
            $table->unsignedInteger('idaction_content_name')->nullable();
            $table->unsignedInteger('idaction_content_piece')->nullable();
            $table->unsignedInteger('idaction_content_target')->nullable();
            $table->unsignedInteger('idaction_content_interaction')->nullable();
            $table->string('search_cat')->nullable();
            $table->unsignedInteger('search_count')->nullable();
            $table->float('custom_float')->nullable();
            $table->dateTime('server_time');
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

    public function test_built_in_segments_apply_bound_filters_and_unknown_segments_fail_closed(): void
    {
        $this->insertVisits();
        $request = new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: 'countryCode==nz',
        );
        $result = $this->archiver()->archive($request);

        $this->assertSame([1], $result->archiveIds);
        $this->assertSame(1, $result->visits);
        $this->assertSame(1, (int) $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', 1)
            ->where('name', 'done'.md5('countryCode==nz'))
            ->value('value'));

        $unsupported = new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: 'extensionDimension==nz',
        );

        try {
            $this->archiver()->archive($unsupported);
            $this->fail('An unsupported segment must not create a successful archive.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('no segment handler supports it', $invalidArgumentException->getMessage());
        }

        $hash = md5('extensionDimension==nz');
        $this->assertSame(2, (int) $this->connection
            ->table('archive_numeric_2026_08')
            ->where('name', 'done'.$hash)
            ->value('value'));
        $this->events->listen(ArchiveVisitsQueryBuilding::class, static function (
            ArchiveVisitsQueryBuilding $event,
        ): void {
            if ($event->request->segment === 'extensionDimension==nz') {
                $event->query->where('location_country', 'nz');
                $event->segmentApplied = true;
            }
        });

        $result = $this->archiver()->archive($unsupported);

        $this->assertSame([3], $result->archiveIds);
        $this->assertSame(1, $result->visits);
        $this->assertSame(1, (int) $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', 3)
            ->where('name', 'done'.$hash)
            ->value('value'));
    }

    public function test_visit_segment_labels_groups_functions_and_binary_values_match_legacy_behavior(): void
    {
        $countryMetadata = $this->createMock(CountryMetadataProvider::class);
        $countryMetadata->method('codes')->willReturn(['nz', 'au', 'fr']);
        $countryMetadata->method('continentCode')->willReturnCallback(
            static fn (string $country): string => match ($country) {
                'nz', 'au' => 'oce',
                'fr' => 'eur',
                default => 'unk',
            },
        );
        $this->app->instance(CountryMetadataProvider::class, $countryMetadata);
        $this->insertVisits();
        $visitorId = '34c31e04394bdc63';
        $this->connection->table('log_visit')
            ->where('idvisitor', 'visitor-b')
            ->update([
                'idvisitor' => hex2bin($visitorId),
                'visit_first_action_time' => '2026-08-15 08:30:00',
                'visitor_returning' => 1,
                'visit_goal_buyer' => 1,
                'visitor_seconds_since_first' => 172_800,
                'config_device_type' => 2,
                'referer_name' => 'weekly newsletter',
                'location_ip' => inet_pton('80.229.12.34'),
            ]);
        $segment = implode(';', [
            'visitorType==returning,deviceType==tablet',
            'visitEcommerceStatus==ordered',
            'referrerName=@newsletter',
            'visitStartServerHour>=8',
            'daysSinceFirstVisit==2',
            'continentCode==oce',
            'visitEndServerDate==2026-08-15',
            "visitorId=={$visitorId}",
            'visitIp>=80.229.0.0',
            'visitIp<=80.229.255.255',
        ]);
        $applicator = $this->visitSegmentApplicator();
        $conditions = explode(';', $segment);

        foreach (array_keys($conditions) as $index) {
            $partial = implode(';', array_slice($conditions, 0, $index + 1));
            $query = $this->connection->table('log_visit')->where('idsite', 1);
            $this->assertTrue($applicator->apply($query, $partial));
            $this->assertSame(1, $query->count(), "The segment '{$partial}' must match one visit.");
        }

        $result = $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
        ));

        $this->assertSame(1, $result->visits);
        $this->assertSame(1.0, $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', $result->archiveIds[0])
            ->where('name', 'nb_visits')
            ->value('value'));
    }

    public function test_visit_segment_string_and_empty_operators_keep_null_and_escape_semantics(): void
    {
        $this->insertVisits();
        $this->connection->table('log_visit')
            ->where('idvisitor', 'visitor-b')
            ->update([
                'referer_name' => 'weekly_news%letter',
                'visitor_returning' => 1,
            ]);
        $this->connection->table('log_visit')
            ->where('idvisitor', 'outside-b')
            ->update(['location_country' => null]);
        $expectedCounts = [
            'referrerName=@news%2525letter' => 1,
            'referrerName!@news' => 3,
            'referrerName=^weekly%255F' => 1,
            'referrerName=$letter' => 1,
            'referrerName==' => 3,
            'referrerName!=' => 1,
            'countryCode==' => 1,
            'countryCode!=' => 3,
            'userId!=bob' => 4,
            'userId!@alice' => 3,
            'referrerName!=0' => 1,
            'referrerName!@0' => 1,
            'visitorType!=new' => 1,
            "referrerName==x' OR 1=1 --" => 0,
        ];
        $applicator = $this->visitSegmentApplicator();

        foreach ($expectedCounts as $segment => $expected) {
            $query = $this->connection->table('log_visit')->where('idsite', 1);
            $this->assertTrue($applicator->apply($query, $segment));
            $this->assertSame($expected, $query->count(), "The segment '{$segment}' has the wrong result.");
        }
    }

    public function test_action_segments_match_same_action_rows_and_negative_rules_exclude_whole_visits(): void
    {
        $this->insertVisits();
        $this->insertActionRows();
        $this->connection->table('log_visit')->where('idvisit', 2)->update([
            'visit_entry_idaction_url' => 1,
            'visit_entry_idaction_name' => 2,
            'visit_exit_idaction_url' => 3,
            'visit_exit_idaction_name' => 4,
        ]);
        $segments = [
            'pageUrl==https%3A%2F%2Fwww.example.test%2Fone' => 1,
            'pageUrl==https%3A%2F%2Fwww.example.test%2Fone;pageTitle==Alpha' => 1,
            'pageUrl==https%3A%2F%2Fwww.example.test%2Fone;pageTitle==Beta' => 0,
            'eventCategory==Video;eventAction==play;eventName==Trailer;eventValue>=9' => 1,
            'actionType==events' => 1,
            'actionServerHour==10' => 1,
            'siteSearchCategory==docs;siteSearchCount==2' => 1,
            'entryPageUrl==https%3A%2F%2Fwww.example.test%2Fone' => 1,
            'entryPageTitle==Alpha;exitPageTitle==Beta' => 1,
            'entryPageTitle!=Alpha' => 1,
            'entryPageTitle==' => 1,
            'entryPageTitle!=' => 1,
            'pageTitle!=Alpha' => 1,
            'pageTitle!@ph' => 1,
            'pageTitle=@Alpha%255F100%2525' => 1,
            'pageTitle!@Alpha%255F100%2525' => 1,
            'pageTitle!=Missing' => 2,
            'countryCode==nz,pageTitle==Alpha' => 2,
            "pageTitle==x' OR 1=1 --" => 0,
        ];

        foreach ($segments as $segment => $expectedVisits) {
            $result = $this->archiver()->archive(new ArchiveReportRequest(
                siteId: 1,
                period: 'day',
                date: '2026-08-15',
                segment: $segment,
            ));

            $this->assertSame(
                $expectedVisits,
                $result->visits,
                "The action segment '{$segment}' has the wrong visit count.",
            );
        }
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
            visitSegments: $this->visitSegmentApplicator(),
            events: $this->events,
        );
    }

    private function visitSegmentApplicator(): BuiltInVisitSegmentApplicator
    {
        return new BuiltInVisitSegmentApplicator(
            new SegmentExpressionParser,
            $this->app->make(CountryMetadataProvider::class),
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

    private function insertActionRows(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 1, 'name' => 'example.test/one', 'type' => 1],
            ['idaction' => 2, 'name' => 'Alpha', 'type' => 4],
            ['idaction' => 3, 'name' => 'example.test/two', 'type' => 1],
            ['idaction' => 4, 'name' => 'Beta', 'type' => 4],
            ['idaction' => 5, 'name' => 'Video', 'type' => 10],
            ['idaction' => 6, 'name' => 'play', 'type' => 11],
            ['idaction' => 7, 'name' => 'Trailer', 'type' => 12],
            ['idaction' => 8, 'name' => 'example.test/trailer', 'type' => 10],
            ['idaction' => 9, 'name' => 'Alpha_100%', 'type' => 4],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 1,
                'idaction_name' => 2,
                'idaction_event_category' => null,
                'idaction_event_action' => null,
                'search_cat' => null,
                'search_count' => null,
                'custom_float' => null,
                'server_time' => '2026-08-15 08:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 3,
                'idaction_name' => 4,
                'idaction_event_category' => null,
                'idaction_event_action' => null,
                'search_cat' => 'docs',
                'search_count' => 2,
                'custom_float' => null,
                'server_time' => '2026-08-15 09:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 8,
                'idaction_name' => 7,
                'idaction_event_category' => 5,
                'idaction_event_action' => 6,
                'search_cat' => null,
                'search_count' => null,
                'custom_float' => 9.5,
                'server_time' => '2026-08-15 10:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 1,
                'idaction_name' => 9,
                'idaction_event_category' => null,
                'idaction_event_action' => null,
                'search_cat' => null,
                'search_count' => null,
                'custom_float' => null,
                'server_time' => '2026-08-15 11:00:00',
            ],
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
