<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\ActionArchiveCollector;
use App\Matomo\Archiving\ActionArchiveConfiguration;
use App\Matomo\Archiving\ActionArchiveMetric;
use App\Matomo\Archiving\ActionArchivePathResolver;
use App\Matomo\Archiving\ArchiveActionQueryFactory;
use App\Matomo\Archiving\ArchiveConversionQueryFactory;
use App\Matomo\Archiving\ArchiveRecordSet;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\ArchiveVisitQueryFactory;
use App\Matomo\Archiving\BrowserLanguageArchiveLabeler;
use App\Matomo\Archiving\BuiltInVisitSegmentApplicator;
use App\Matomo\Archiving\CarbonReportingSubperiodFactory;
use App\Matomo\Archiving\ContentArchiveCollector;
use App\Matomo\Archiving\DatabaseReportArchiver;
use App\Matomo\Archiving\DynamicSegmentResolver;
use App\Matomo\Archiving\EcommerceItemArchiveCollector;
use App\Matomo\Archiving\EventArchiveCollector;
use App\Matomo\Archiving\Events\ActionArchiveMetricsCollecting;
use App\Matomo\Archiving\Events\ArchiveActionsQueryBuilding;
use App\Matomo\Archiving\Events\ArchiveConversionsQueryBuilding;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\Events\ArchiveReportsCompleted;
use App\Matomo\Archiving\Events\ArchiveReportsStarting;
use App\Matomo\Archiving\Events\ArchiveVisitsQueryBuilding;
use App\Matomo\Archiving\ExamplePluginArchiveCollector;
use App\Matomo\Archiving\GoalArchiveCollector;
use App\Matomo\Archiving\PagePerformanceArchiveCollector;
use App\Matomo\Archiving\SegmentConditionQueryApplier;
use App\Matomo\Archiving\SegmentDefinitionValidator;
use App\Matomo\Archiving\SegmentExpressionParser;
use App\Matomo\Archiving\TrustedSegmentSqlExpression;
use App\Matomo\Archiving\VisitAggregateArchiveCollector;
use App\Matomo\Archiving\VisitDimensionArchiveCollector;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Goals\DatabaseGoalRepository;
use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Reporting\CarbonReportingPeriodFactory;
use App\Matomo\Reporting\DatabaseBlobArchiveRepository;
use App\Matomo\Reporting\DatabaseSegmentHashResolver;
use App\Matomo\Reporting\DatabaseVisitsSummaryArchiveRepository;
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
            $table->string('main_url')->nullable();
            $table->boolean('ecommerce')->default(false);
            $table->boolean('sitesearch')->default(true);
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
            $table->string('config_browser_name')->nullable();
            $table->string('config_browser_version')->nullable();
            $table->string('config_os')->nullable();
            $table->string('config_os_version')->nullable();
            $table->string('config_resolution')->nullable();
            $table->string('config_device_brand')->nullable();
            $table->string('config_device_model')->nullable();
            $table->string('config_browser_engine')->nullable();
            $table->string('location_provider')->nullable();
            $table->dateTime('visitor_localtime')->nullable();
            $table->string('custom_var_k1')->nullable();
            $table->string('custom_var_v1')->nullable();
            $table->string('custom_var_k2')->nullable();
            $table->string('custom_var_v2')->nullable();
            $table->string('custom_var_k3')->nullable();
            $table->string('custom_var_v3')->nullable();
            $table->string('custom_var_k4')->nullable();
            $table->string('custom_var_v4')->nullable();
            $table->string('custom_var_k5')->nullable();
            $table->string('custom_var_v5')->nullable();
            $table->string('custom_dimension_1')->nullable();
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
            $table->unsignedInteger('visitor_count_visits')->nullable();
            $table->unsignedTinyInteger('config_device_type')->nullable();
            $table->boolean('config_cookie')->nullable();
            $table->boolean('config_flash')->nullable();
            $table->boolean('config_java')->nullable();
            $table->boolean('config_pdf')->nullable();
            $table->boolean('config_quicktime')->nullable();
            $table->boolean('config_realplayer')->nullable();
            $table->boolean('config_silverlight')->nullable();
            $table->boolean('config_windowsmedia')->nullable();
            $table->string('referer_name')->nullable();
            $table->string('referer_url')->nullable();
            $table->binary('location_ip')->nullable();
            $table->string('location_country', 3)->nullable();
            $table->string('location_browser_lang')->nullable();
            $table->string('location_region')->nullable();
            $table->string('location_city')->nullable();
            $table->float('location_latitude')->nullable();
            $table->float('location_longitude')->nullable();
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
            $table->unsignedInteger('idaction_url_ref')->nullable();
            $table->unsignedInteger('idaction_name_ref')->nullable();
            $table->unsignedInteger('idaction_event_category')->nullable();
            $table->unsignedInteger('idaction_event_action')->nullable();
            $table->unsignedInteger('idaction_content_name')->nullable();
            $table->unsignedInteger('idaction_content_piece')->nullable();
            $table->unsignedInteger('idaction_content_target')->nullable();
            $table->unsignedInteger('idaction_content_interaction')->nullable();
            $table->unsignedInteger('idaction_product_name')->nullable();
            $table->unsignedInteger('idaction_product_sku')->nullable();
            $table->unsignedInteger('idaction_product_cat')->nullable();
            $table->unsignedInteger('idaction_product_cat2')->nullable();
            $table->unsignedInteger('idaction_product_cat3')->nullable();
            $table->unsignedInteger('idaction_product_cat4')->nullable();
            $table->unsignedInteger('idaction_product_cat5')->nullable();
            $table->string('search_cat')->nullable();
            $table->unsignedInteger('search_count')->nullable();
            $table->float('custom_float')->nullable();
            $table->float('product_price')->nullable();
            $table->unsignedBigInteger('bandwidth')->nullable();
            $table->unsignedInteger('time_spent_ref_action')->default(0);
            $table->unsignedInteger('time_network')->nullable();
            $table->unsignedInteger('time_server')->nullable();
            $table->unsignedInteger('time_transfer')->nullable();
            $table->unsignedInteger('time_dom_processing')->nullable();
            $table->unsignedInteger('time_dom_completion')->nullable();
            $table->unsignedInteger('time_on_load')->nullable();
            $table->string('custom_var_k1')->nullable();
            $table->string('custom_var_v1')->nullable();
            $table->string('custom_var_k2')->nullable();
            $table->string('custom_var_v2')->nullable();
            $table->string('custom_var_k3')->nullable();
            $table->string('custom_var_v3')->nullable();
            $table->string('custom_var_k4')->nullable();
            $table->string('custom_var_v4')->nullable();
            $table->string('custom_var_k5')->nullable();
            $table->string('custom_var_v5')->nullable();
            $table->string('custom_dimension_2')->nullable();
            $table->string('custom_dimension_4')->nullable();
            $table->dateTime('server_time');
        });
        $schema->create('goal', static function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->integer('idgoal');
            $table->string('name');
            $table->boolean('deleted')->default(false);
        });
        $schema->create('log_conversion', static function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->unsignedInteger('idvisit');
            $table->integer('idgoal');
            $table->string('idorder')->nullable();
            $table->float('revenue')->nullable();
            $table->float('revenue_subtotal')->nullable();
            $table->float('revenue_tax')->nullable();
            $table->float('revenue_shipping')->nullable();
            $table->float('revenue_discount')->nullable();
            $table->unsignedInteger('items')->nullable();
            $table->unsignedInteger('visitor_count_visits')->nullable();
            $table->unsignedInteger('visitor_seconds_since_first')->nullable();
            $table->dateTime('server_time')->nullable();
            $table->string('config_device_type')->nullable();
            $table->string('config_device_brand')->nullable();
            $table->string('config_device_model')->nullable();
            $table->string('config_browser_name')->nullable();
            $table->string('location_country')->nullable();
            $table->string('location_region')->nullable();
            $table->string('location_city')->nullable();
            $table->string('custom_dimension_3')->nullable();
        });
        $schema->create('log_conversion_item', static function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->unsignedInteger('idvisit');
            $table->string('idorder')->nullable();
            $table->unsignedInteger('idaction_sku')->nullable();
            $table->unsignedInteger('idaction_name')->nullable();
            $table->unsignedInteger('idaction_category')->nullable();
            $table->unsignedInteger('idaction_category2')->nullable();
            $table->unsignedInteger('idaction_category3')->nullable();
            $table->unsignedInteger('idaction_category4')->nullable();
            $table->unsignedInteger('idaction_category5')->nullable();
            $table->float('price')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('deleted')->default(false);
            $table->dateTime('server_time')->nullable();
        });
        $schema->create('custom_dimensions', static function (Blueprint $table): void {
            $table->unsignedInteger('idcustomdimension');
            $table->unsignedInteger('idsite');
            $table->unsignedSmallInteger('index');
            $table->string('scope', 10);
            $table->boolean('active');
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

    public function test_conversion_and_ecommerce_segments_preserve_related_row_semantics(): void
    {
        $this->insertVisits();
        $this->insertActionRows();
        $this->insertConversionRows();
        $this->connection->table('log_visit')->where('idvisit', 1)->update([
            'visit_first_action_time' => '2026-08-14 12:00:00',
        ]);
        $this->connection->table('log_visit')->where('idvisit', 2)->update([
            'visit_first_action_time' => '2026-08-15 10:00:00',
        ]);
        $this->connection->table('log_visit')->insert(
            $this->visit(1, '2026-08-15 10:30:00', 'visitor-c', 'config-c', null, 1, 5, 0, 'nz'),
        );
        $segments = [
            'visitConvertedGoalId==1' => 1,
            'visitConvertedGoalId!=1' => 2,
            'visitConvertedGoalId==' => 2,
            'visitConvertedGoalId!=' => 2,
            'visitConvertedGoalName==Newsletter' => 1,
            'visitConvertedGoalName==Other Site Goal' => 0,
            'visitConvertedGoalName==' => 2,
            'visitConvertedGoalName!=' => 2,
            'orderId==ORDER-2' => 1,
            'orderId==' => 0,
            'revenueOrder>100' => 1,
            'revenueAbandonedCart>=45' => 1,
            'revenueOrder>100;revenueAbandonedCart>=45' => 1,
            'visitConvertedGoalId==0;orderId==ORDER-2' => 1,
            'visitConvertedGoalId==2;orderId==ORDER-2' => 0,
            'visitConvertedGoalId==2,orderId==MISSING' => 1,
            'productName==Widget' => 1,
            'productSku==SKU-2' => 1,
            'productCategory==Tools' => 1,
            'productCategory2==Sale' => 1,
            'productPrice>=49' => 1,
            'productName==Widget;productSku==SKU-2' => 1,
            'productName==Widget;productSku==OTHER' => 0,
            'productName!=Widget' => 2,
            'productName==' => 0,
            'productPrice==' => 2,
            'productPrice!=' => 1,
            'productViewName==Viewed Widget' => 1,
            'productViewSku==VIEW-SKU' => 1,
            'productViewCategory==Viewed Tools' => 1,
            'productViewCategory2==Featured' => 1,
            'productViewPrice>=40' => 1,
            'siteSearchCategory==' => 3,
            'siteSearchCategory!=' => 1,
            'productViewPrice==' => 3,
            'productViewPrice!=' => 1,
            'productViewName==' => 0,
            "orderId==x' OR 1=1 --" => 0,
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
                "The conversion segment '{$segment}' has the wrong visit count.",
            );
        }

        $period = (new CarbonReportingPeriodFactory)->make(
            'day',
            '2026-08-15',
            'Pacific/Auckland',
        )[0][0];
        $conversionQueries = new ArchiveConversionQueryFactory(
            $this->connection,
            $this->visitSegmentApplicator(),
            $this->events,
        );
        $conversionSegments = [
            'visitConvertedGoalId==1' => 1,
            'visitConvertedGoalName==Newsletter' => 1,
            'visitConvertedGoalId==2,orderId==ORDER-2' => 2,
            'visitConvertedGoalId==0;orderId==ORDER-2' => 1,
            'productName==Widget' => 1,
            'productName==Widget,visitConvertedGoalId==2' => 2,
            'countryCode==au' => 3,
            'visitConvertedGoalId==1,countryCode==au' => 4,
            'visitStartServerHour==12' => 1,
        ];

        foreach ($conversionSegments as $segment => $expectedConversions) {
            $count = $conversionQueries->make(
                new ArchiveReportRequest(1, 'day', '2026-08-15', segment: $segment),
                $period,
                'Pacific/Auckland',
            )->count();

            $this->assertSame(
                $expectedConversions,
                $count,
                "The conversion archive segment '{$segment}' has the wrong conversion count.",
            );
        }

        $this->events->listen(ArchiveConversionsQueryBuilding::class, static function (
            ArchiveConversionsQueryBuilding $event,
        ): void {
            if ($event->request->segment === 'extensionConversion==one') {
                $event->query->where('log_conversion.idgoal', 1);
                $event->segmentApplied = true;
            }
        });
        $extensionCount = $conversionQueries->make(
            new ArchiveReportRequest(
                1,
                'day',
                '2026-08-15',
                segment: 'extensionConversion==one',
            ),
            $period,
            'Pacific/Auckland',
        )->count();
        $this->assertSame(1, $extensionCount);
    }

    public function test_revenue_segments_reject_legacy_unsupported_operators(): void
    {
        $query = $this->connection->table('log_visit');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The 'revenueOrder' segment does not support the != operator.");

        $this->visitSegmentApplicator()->apply($query, 'revenueOrder!=10');
    }

    public function test_dynamic_dimensions_custom_variables_and_display_aliases_are_supported(): void
    {
        $countryMetadata = $this->createMock(CountryMetadataProvider::class);
        $countryMetadata->method('codes')->willReturn(['nz', 'au']);
        $countryMetadata->method('continentCode')->willReturn('oce');
        $countryMetadata->method('countryName')->willReturnCallback(
            static fn (string $country): string => match ($country) {
                'nz' => 'New Zealand',
                'au' => 'Australia',
                default => $country,
            },
        );
        $this->app->instance(CountryMetadataProvider::class, $countryMetadata);
        $this->insertVisits();
        $this->insertActionRows();
        $this->insertConversionRows();
        $this->connection->table('custom_dimensions')->insert([
            ['idcustomdimension' => 1, 'idsite' => 1, 'index' => 1, 'scope' => 'visit', 'active' => 1],
            ['idcustomdimension' => 2, 'idsite' => 1, 'index' => 2, 'scope' => 'action', 'active' => 1],
            ['idcustomdimension' => 3, 'idsite' => 1, 'index' => 3, 'scope' => 'conversion', 'active' => 1],
            ['idcustomdimension' => 4, 'idsite' => 1, 'index' => 4, 'scope' => 'action', 'active' => 1],
            ['idcustomdimension' => 5, 'idsite' => 1, 'index' => 1, 'scope' => 'visit', 'active' => 0],
        ]);
        $this->connection->table('log_visit')->where('idvisit', 1)->update([
            'config_browser_name' => 'FF',
            'config_os' => 'WIN',
            'custom_var_k1' => 'Plan',
            'custom_var_v1' => 'Gold',
            'custom_dimension_1' => 'visit-dimension',
        ]);
        $this->connection->table('log_visit')->where('idvisit', 2)->update([
            'config_browser_name' => 'CH',
            'config_os' => 'LIN',
            'custom_var_k2' => 'Plan',
            'custom_var_v2' => 'Silver',
        ]);
        $this->connection->table('log_link_visit_action')->where('idlink_va', 1)->update([
            'custom_var_k1' => 'Category',
            'custom_var_v1' => 'News',
            'custom_dimension_2' => 'action-dimension',
            'custom_dimension_4' => 'pair',
            'bandwidth' => 1000,
        ]);
        $this->connection->table('log_link_visit_action')->where('idlink_va', 2)->update([
            'custom_var_k2' => 'Audience',
            'custom_var_v2' => 'Pro',
            'custom_dimension_2' => 'other-action',
            'custom_dimension_4' => 'other-pair',
            'bandwidth' => 2000,
        ]);
        $this->connection->table('log_conversion')
            ->where('idvisit', 2)
            ->where('idgoal', 0)
            ->update(['custom_dimension_3' => 'conversion-dimension']);
        $countryQuery = $this->connection->table('log_visit')->where('idsite', 1);
        $this->assertTrue($this->visitSegmentApplicator()->apply(
            $countryQuery,
            'countryName==New Zealand',
            1,
        ));
        $this->assertContains('nz', $countryQuery->getBindings());
        $segments = [
            'dimension1==visit-dimension' => 1,
            'dimension2==action-dimension' => 1,
            'dimension3==conversion-dimension' => 1,
            'dimension2==action-dimension;dimension4==pair' => 1,
            'dimension2==action-dimension;dimension4==other-pair' => 0,
            'customVariableName==Plan' => 2,
            'customVariableName1==Plan' => 1,
            'customVariableValue==Gold' => 1,
            'customVariablePageName==Category' => 1,
            'customVariablePageName==Category;customVariablePageValue==News' => 1,
            'customVariablePageName==Category;customVariablePageValue==Pro' => 0,
            'customVariablePageValue!=News' => 1,
            'bandwidth>=2000' => 1,
            'browserName==Firefox' => 1,
            'operatingSystemName==Windows' => 1,
            'countryName==New Zealand' => 1,
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
                "The dynamic segment '{$segment}' has the wrong visit count.",
            );
        }

        $period = (new CarbonReportingPeriodFactory)->make(
            'day',
            '2026-08-15',
            'Pacific/Auckland',
        )[0][0];
        $conversionCount = (new ArchiveConversionQueryFactory(
            $this->connection,
            $this->visitSegmentApplicator(),
            $this->events,
        ))->make(
            new ArchiveReportRequest(
                1,
                'day',
                '2026-08-15',
                segment: 'dimension3==conversion-dimension',
            ),
            $period,
            'Pacific/Auckland',
        )->count();
        $this->assertSame(1, $conversionCount);

        $query = $this->connection->table('log_visit');
        $sql = $query->toSql();
        $this->assertFalse($this->visitSegmentApplicator()->apply($query, 'dimension5==hidden', 1));
        $this->assertSame($sql, $query->toSql());
        $this->assertFalse($this->visitSegmentApplicator()->apply($query, 'dimension1==other-site', 2));
        $this->assertSame($sql, $query->toSql());
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

    public function test_collects_visit_dimension_records_and_aggregates_parent_blobs(): void
    {
        $this->insertVisits();
        $this->connection->table('log_visit')->where('idvisit', 1)->update([
            'visit_first_action_time' => '2026-08-14 12:00:00',
            'visitor_localtime' => '2026-08-15 07:00:00',
            'config_browser_name' => 'FF',
            'config_browser_version' => '142.0',
            'config_os' => 'LIN',
            'config_os_version' => '6.12',
            'config_resolution' => '1920x1080',
            'config_device_type' => 0,
            'config_device_brand' => 'Unknown',
            'config_device_model' => 'Desktop',
            'config_browser_engine' => 'Gecko',
            'location_provider' => 'example.net',
            'location_browser_lang' => 'fr-fr,fr;q=0.8',
            'location_region' => 'AUK',
            'location_city' => 'Auckland',
            'location_latitude' => -36.8485,
            'location_longitude' => 174.7633,
            'visitor_count_visits' => 1,
            'visitor_returning' => 0,
            'visitor_seconds_since_last' => 0,
            'config_java' => 1,
            'config_pdf' => 1,
        ]);
        $this->connection->table('log_visit')->where('idvisit', 2)->update([
            'visit_first_action_time' => '2026-08-15 11:00:00',
            'visitor_localtime' => '2026-08-15 22:00:00',
            'config_browser_name' => 'CH',
            'config_browser_version' => '140.0',
            'config_os' => 'WIN',
            'config_os_version' => '11',
            'config_resolution' => '1366x768',
            'config_device_type' => 1,
            'config_device_brand' => 'Google',
            'config_device_model' => 'Pixel',
            'config_browser_engine' => 'Blink',
            'location_provider' => 'isp.test',
            'location_browser_lang' => 'en-us,en;q=0.5',
            'location_region' => 'NSW',
            'location_city' => 'Sydney',
            'location_latitude' => -33.8688,
            'location_longitude' => 151.2093,
            'visitor_count_visits' => 3,
            'visitor_returning' => 1,
            'visitor_seconds_since_last' => 172_800,
            'config_java' => 1,
            'config_pdf' => 0,
        ]);
        $this->connection->table('log_conversion')->insert([
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idgoal' => 1,
                'idorder' => null,
                'revenue' => 50,
                'revenue_subtotal' => null,
                'revenue_tax' => null,
                'revenue_shipping' => null,
                'revenue_discount' => null,
                'items' => null,
                'server_time' => '2026-08-14 13:30:00',
                'config_device_type' => 0,
                'config_device_brand' => 'Unknown',
                'config_device_model' => 'Desktop',
                'config_browser_name' => 'FF',
                'location_country' => 'nz',
                'location_region' => 'AUK',
                'location_city' => 'Auckland',
            ],
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idgoal' => 2,
                'idorder' => null,
                'revenue' => 1_000_000_000_001,
                'revenue_subtotal' => null,
                'revenue_tax' => null,
                'revenue_shipping' => null,
                'revenue_discount' => null,
                'items' => null,
                'server_time' => '2026-08-14 13:45:00',
                'config_device_type' => 0,
                'config_device_brand' => 'Unknown',
                'config_device_model' => 'Desktop',
                'config_browser_name' => 'OP',
                'location_country' => 'nz',
                'location_region' => 'AUK',
                'location_city' => 'Auckland',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idgoal' => 0,
                'idorder' => 'ORDER-2',
                'revenue' => 125,
                'revenue_subtotal' => 100,
                'revenue_tax' => 10,
                'revenue_shipping' => 15,
                'revenue_discount' => 0,
                'items' => 3,
                'server_time' => '2026-08-15 10:00:00',
                'config_device_type' => 1,
                'config_device_brand' => 'Google',
                'config_device_model' => 'Pixel',
                'config_browser_name' => 'CH',
                'location_country' => 'au',
                'location_region' => 'NSW',
                'location_city' => 'Sydney',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idgoal' => -1,
                'idorder' => null,
                'revenue' => 45,
                'revenue_subtotal' => null,
                'revenue_tax' => null,
                'revenue_shipping' => null,
                'revenue_discount' => null,
                'items' => 2,
                'server_time' => '2026-08-15 10:30:00',
                'config_device_type' => 1,
                'config_device_brand' => 'Google',
                'config_device_model' => 'Pixel',
                'config_browser_name' => 'CH',
                'location_country' => 'au',
                'location_region' => 'NSW',
                'location_city' => 'Sydney',
            ],
        ]);
        $this->registerVisitDimensionCollector();

        $this->archiver()->archive(new ArchiveReportRequest(1, 'day', '2026-08-15'));

        $period = (new CarbonReportingPeriodFactory)->make(
            'day',
            '2026-08-15',
            'Pacific/Auckland',
        )[0][0];
        $repository = new DatabaseBlobArchiveRepository($this->connection);
        $browserRows = $repository->rows(
            [1],
            [$period],
            '',
            'DevicesDetection_browserVersions',
        )[1][$period->rangeKey()];
        $this->assertSame(['CH;140.0', 'FF;142.0'], array_column(array_column($browserRows, 'columns'), 'label'));
        $this->assertSame([1, 1], array_column(array_column($browserRows, 'columns'), 'nb_visits'));
        $browserFamilyRows = $repository->rows(
            [1],
            [$period],
            '',
            'DevicesDetection_browsers',
        )[1][$period->rangeKey()];
        $browsersByLabel = [];

        foreach ($browserFamilyRows as $row) {
            $browsersByLabel[(string) $row['columns']['label']] = $row['columns'];
        }

        $this->assertSame(1, $browsersByLabel['CH']['nb_conversions']);
        $this->assertSame(125, $browsersByLabel['CH']['revenue']);
        $this->assertSame(1, $browsersByLabel['CH']['goal_0_nb_conversions']);
        $this->assertSame(3, $browsersByLabel['CH']['goal_0_items']);
        $this->assertSame(100, $browsersByLabel['CH']['goal_0_revenue_subtotal']);
        $this->assertSame(1, $browsersByLabel['CH']['goal_-1_nb_conversions']);
        $this->assertSame(2, $browsersByLabel['CH']['goal_-1_items']);
        $this->assertSame(1, $browsersByLabel['FF']['nb_conversions']);
        $this->assertSame(50, $browsersByLabel['FF']['revenue']);
        $this->assertSame(0, $browsersByLabel['OP']['nb_visits']);
        $this->assertSame(1, $browsersByLabel['OP']['nb_conversions']);
        $this->assertSame(0, $browsersByLabel['OP']['goal_2_revenue']);
        $localTimeRows = $repository->rows(
            [1],
            [$period],
            '',
            'VisitTime_localTime',
        )[1][$period->rangeKey()];
        $this->assertCount(24, $localTimeRows);
        $this->assertSame(range(0, 23), array_column(array_column($localTimeRows, 'columns'), 'label'));
        $this->assertSame(1, $localTimeRows[7]['columns']['nb_visits']);
        $this->assertSame(1, $localTimeRows[22]['columns']['nb_visits']);
        $serverTimeRows = $repository->rows(
            [1],
            [$period],
            '',
            'VisitTime_serverTime',
        )[1][$period->rangeKey()];
        $this->assertCount(24, $serverTimeRows);
        $this->assertSame(1, $serverTimeRows[0]['columns']['nb_visits']);
        $this->assertSame(1, $serverTimeRows[23]['columns']['nb_visits']);
        $this->assertSame(2, $serverTimeRows[1]['columns']['nb_conversions']);
        $this->assertSame(1, $serverTimeRows[22]['columns']['nb_conversions']);
        $userRows = $repository->rows(
            [1],
            [$period],
            '',
            'UserId_users',
        )[1][$period->rangeKey()];
        $this->assertSame('alice', $userRows[0]['columns']['label']);
        $this->assertSame(bin2hex('visitor-a'), $userRows[0]['metadata']['idvisitor']);
        $cityRows = $repository->rows(
            [1],
            [$period],
            '',
            'UserCountry_city',
        )[1][$period->rangeKey()];
        $this->assertSame(['Auckland|AUK|nz', 'Sydney|NSW|au'], array_column(array_column($cityRows, 'columns'), 'label'));
        $this->assertSame(-36.85, $cityRows[0]['metadata']['lat']);
        $this->assertSame(174.76, $cityRows[0]['metadata']['long']);
        $languageRows = $repository->rows(
            [1],
            [$period],
            '',
            'UserLanguage_language',
        )[1][$period->rangeKey()];
        $this->assertSame(['en-us', 'fr'], array_column(array_column($languageRows, 'columns'), 'label'));
        $this->assertSame(2.0, $this->connection
            ->table('archive_numeric_2026_08')
            ->where('idarchive', 1)
            ->where('name', 'UserCountry_distinctCountries')
            ->value('value'));
        $interestRows = $repository->rows(
            [1],
            [$period],
            '',
            'VisitorInterest_daysSinceLastVisit',
        )[1][$period->rangeKey()];
        $interestByLabel = [];

        foreach ($interestRows as $row) {
            $interestByLabel[(string) $row['columns']['label']] = $row['columns']['nb_visits'];
        }

        $this->assertSame(1, $interestByLabel['General_NewVisits']);
        $this->assertSame(1, $interestByLabel['2-2']);
        $pluginRows = $repository->rows(
            [1],
            [$period],
            '',
            'DevicePlugins_plugin',
        )[1][$period->rangeKey()];
        $pluginsByLabel = [];

        foreach ($pluginRows as $row) {
            $pluginsByLabel[(string) $row['columns']['label']] = $row['columns']['nb_visits'];
        }

        $this->assertSame(2, $pluginsByLabel['java']);
        $this->assertSame(1, $pluginsByLabel['pdf']);

        $goalSegment = 'visitConvertedGoalId==1';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $goalSegment,
            plugin: 'DevicesDetection',
            reports: ['DevicesDetection.getBrowsers'],
        ));
        $goalSegmentRows = $repository->rows(
            [1],
            [$period],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($goalSegment),
            'DevicesDetection_browsers',
        )[1][$period->rangeKey()];
        $this->assertCount(1, $goalSegmentRows);
        $this->assertSame('FF', $goalSegmentRows[0]['columns']['label']);
        $this->assertSame(1, $goalSegmentRows[0]['columns']['goal_1_nb_conversions']);
        $this->assertArrayNotHasKey('goal_2_nb_conversions', $goalSegmentRows[0]['columns']);

        $weekResult = $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            force: true,
        ));
        $week = (new CarbonReportingPeriodFactory)->make(
            'week',
            '2026-08-15',
            'Pacific/Auckland',
        )[0][0];
        $weekRows = $repository->rows(
            [1],
            [$week],
            '',
            'DevicesDetection_browserVersions',
        )[1][$week->rangeKey()];
        $weekByLabel = [];

        foreach ($weekRows as $row) {
            $weekByLabel[(string) $row['columns']['label']] = $row['columns'];
        }

        $this->assertSame(2, $weekByLabel['']['nb_visits']);
        $this->assertSame(2, $weekByLabel['']['sum_daily_nb_uniq_visitors']);
        $this->assertSame(1, $weekByLabel['CH;140.0']['nb_visits']);
        $this->assertSame(1, $weekByLabel['FF;142.0']['sum_daily_nb_uniq_visitors']);
        $weekBrowserRows = $repository->rows(
            [1],
            [$week],
            '',
            'DevicesDetection_browsers',
        )[1][$week->rangeKey()];
        $weekBrowsersByLabel = [];

        foreach ($weekBrowserRows as $row) {
            $weekBrowsersByLabel[(string) $row['columns']['label']] = $row['columns'];
        }

        $this->assertSame(1, $weekBrowsersByLabel['CH']['nb_conversions']);
        $this->assertSame(125, $weekBrowsersByLabel['CH']['revenue']);
        $this->assertSame(1, $weekBrowsersByLabel['CH']['goal_0_nb_conversions']);
        $this->assertSame(1, $weekBrowsersByLabel['FF']['goal_1_nb_conversions']);
        $this->assertSame(0, $weekBrowsersByLabel['OP']['nb_visits']);
        $this->assertSame(1, $weekBrowsersByLabel['OP']['goal_2_nb_conversions']);
        $weekPluginRows = $repository->rows(
            [1],
            [$week],
            '',
            'DevicePlugins_plugin',
        )[1][$week->rangeKey()];
        $weekPluginsByLabel = [];

        foreach ($weekPluginRows as $row) {
            $weekPluginsByLabel[(string) $row['columns']['label']] = $row['columns']['nb_visits'];
        }

        $this->assertSame(2, $weekPluginsByLabel['java']);
        $this->assertCount(1, $weekResult->archiveIds);
    }

    public function test_visit_dimension_records_use_a_bounded_others_row(): void
    {
        $visits = [];

        for ($index = 0; $index < 501; $index++) {
            $visits[] = [
                ...$this->visit(
                    1,
                    '2026-08-15 00:00:00',
                    'visitor-'.$index,
                    'config-'.$index,
                    null,
                    1,
                    5,
                    0,
                    'nz',
                ),
                'config_resolution' => str_pad((string) $index, 6, '0', STR_PAD_LEFT).'x1000',
            ];
        }

        foreach (array_chunk($visits, 100) as $chunk) {
            $this->connection->table('log_visit')->insert($chunk);
        }

        $this->registerVisitDimensionCollector();
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'Resolution',
            reports: ['Resolution.getResolution'],
        ));
        $period = (new CarbonReportingPeriodFactory)->make(
            'day',
            '2026-08-15',
            'Pacific/Auckland',
        )[0][0];
        $rows = (new DatabaseBlobArchiveRepository($this->connection))->rows(
            [1],
            [$period],
            '',
            'Resolution_resolution',
        )[1][$period->rangeKey()];

        $this->assertCount(500, $rows);
        $this->assertSame(-1, $rows[499]['columns']['label']);
        $this->assertSame(2, $rows[499]['columns']['nb_visits']);
        $this->assertSame(2, $rows[499]['columns']['nb_uniq_visitors']);
    }

    public function test_collects_goal_metrics_ranges_segments_and_parent_records(): void
    {
        $this->insertVisits();
        $this->insertActionRows();
        $this->insertConversionRows();
        $this->connection->table('goal')->insert([
            'idsite' => 1,
            'idgoal' => 3,
            'name' => 'Unused goal',
        ]);
        $this->connection->table('log_visit')->where('idvisit', 1)->update([
            'visit_goal_converted' => 1,
        ]);
        $this->connection->table('log_conversion')->where('idgoal', 1)->update([
            'visitor_count_visits' => 1,
            'visitor_seconds_since_first' => 0,
        ]);
        $this->connection->table('log_conversion')->where('idgoal', 2)->update([
            'visitor_count_visits' => 12,
            'visitor_seconds_since_first' => 172_800,
        ]);
        $this->connection->table('log_conversion')->where('idgoal', 0)->update([
            'revenue_subtotal' => 100,
            'revenue_tax' => 10,
            'revenue_shipping' => 15,
            'revenue_discount' => 5,
            'items' => 3,
            'visitor_count_visits' => 3,
            'visitor_seconds_since_first' => 86_400,
        ]);
        $this->connection->table('log_conversion')->where('idgoal', -1)->update([
            'items' => 2,
            'visitor_count_visits' => 3,
            'visitor_seconds_since_first' => 86_400,
        ]);
        $this->registerGoalCollector();

        $this->archiver()->archive(new ArchiveReportRequest(1, 'day', '2026-08-15'));

        $periods = new CarbonReportingPeriodFactory;
        $day = $periods->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $numbers = new DatabaseVisitsSummaryArchiveRepository($this->connection);
        $numeric = $numbers->pluginMetrics(
            [1],
            [$day],
            '',
            [
                'Goal_nb_conversions',
                'Goal_nb_visits_converted',
                'Goal_revenue',
                'Goal_1_nb_conversions',
                'Goal_1_nb_visits_converted',
                'Goal_1_revenue',
                'Goal_0_revenue_subtotal',
                'Goal_0_revenue_tax',
                'Goal_0_revenue_shipping',
                'Goal_0_revenue_discount',
                'Goal_0_items',
                'Goal_-1_nb_conversions',
                'Goal_-1_items',
                'Goal_3_nb_conversions',
            ],
            'Goals',
        )[1][$day->rangeKey()];
        $this->assertSame(3, $numeric['Goal_nb_conversions']);
        $this->assertSame(2, $numeric['Goal_nb_visits_converted']);
        $this->assertSame(185, $numeric['Goal_revenue']);
        $this->assertSame(1, $numeric['Goal_1_nb_conversions']);
        $this->assertSame(1, $numeric['Goal_1_nb_visits_converted']);
        $this->assertSame(50, $numeric['Goal_1_revenue']);
        $this->assertSame(100, $numeric['Goal_0_revenue_subtotal']);
        $this->assertSame(10, $numeric['Goal_0_revenue_tax']);
        $this->assertSame(15, $numeric['Goal_0_revenue_shipping']);
        $this->assertSame(5, $numeric['Goal_0_revenue_discount']);
        $this->assertSame(3, $numeric['Goal_0_items']);
        $this->assertSame(1, $numeric['Goal_-1_nb_conversions']);
        $this->assertSame(2, $numeric['Goal_-1_items']);
        $this->assertSame(0, $numeric['Goal_3_nb_conversions']);

        $blobs = new DatabaseBlobArchiveRepository($this->connection);
        $overviewVisits = $this->rangeValues($blobs->rows(
            [1],
            [$day],
            '',
            'Goal_visits_until_conv',
        )[1][$day->rangeKey()]);
        $this->assertSame(1, $overviewVisits['1-1']);
        $this->assertSame(1, $overviewVisits['9-14']);
        $this->assertSame(0, $overviewVisits['3-3']);
        $overviewDays = $this->rangeValues($blobs->rows(
            [1],
            [$day],
            '',
            'Goal_days_until_conv',
        )[1][$day->rangeKey()]);
        $this->assertSame(1, $overviewDays['0-0']);
        $this->assertSame(1, $overviewDays['2-2']);
        $this->assertSame([], $blobs->rows(
            [1],
            [$day],
            '',
            'Goal_3_visits_until_conv',
        )[1][$day->rangeKey()]);

        $segment = 'visitConvertedGoalId==1';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
            plugin: 'Goals',
        ));
        $segmentHash = (new DatabaseSegmentHashResolver($this->connection))->resolve($segment);
        $segmentNumeric = $numbers->pluginMetrics(
            [1],
            [$day],
            $segmentHash,
            ['Goal_nb_conversions', 'Goal_nb_visits_converted', 'Goal_revenue'],
            'Goals',
        )[1][$day->rangeKey()];
        $this->assertSame([
            'Goal_nb_conversions' => 1,
            'Goal_nb_visits_converted' => 1,
            'Goal_revenue' => 50,
        ], $segmentNumeric);

        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            force: true,
        ));
        $week = $periods->make('week', '2026-08-15', 'Pacific/Auckland')[0][0];
        $weekNumeric = $numbers->pluginMetrics(
            [1],
            [$week],
            '',
            ['Goal_nb_conversions', 'Goal_nb_visits_converted', 'Goal_revenue'],
            'Goals',
        )[1][$week->rangeKey()];
        $this->assertSame([
            'Goal_nb_conversions' => 3,
            'Goal_nb_visits_converted' => 2,
            'Goal_revenue' => 185,
        ], $weekNumeric);
        $weekVisits = $this->rangeValues($blobs->rows(
            [1],
            [$week],
            '',
            'Goal_visits_until_conv',
        )[1][$week->rangeKey()]);
        $this->assertSame(1, $weekVisits['1-1']);
        $this->assertSame(1, $weekVisits['9-14']);
    }

    public function test_collects_ecommerce_item_views_carts_segments_and_parent_records(): void
    {
        $this->insertVisits();
        $this->insertActionRows();
        $this->insertConversionRows();
        $this->connection->table('site')->where('idsite', 1)->update(['ecommerce' => 1]);
        $this->connection->table('log_conversion_item')->where('idaction_sku', 21)->update([
            'quantity' => 2,
            'server_time' => '2026-08-15 10:00:00',
        ]);
        $this->connection->table('log_conversion_item')->where('idaction_sku', 25)->update([
            'quantity' => 1,
            'server_time' => '2026-08-15 10:00:00',
        ]);
        $this->connection->table('log_conversion_item')->insert([
            'idsite' => 1,
            'idvisit' => 2,
            'idorder' => '0',
            'idaction_sku' => 21,
            'idaction_name' => 20,
            'idaction_category' => 22,
            'idaction_category2' => 23,
            'price' => 20,
            'quantity' => 3,
            'server_time' => '2026-08-15 10:05:00',
        ]);
        $this->connection->table('log_conversion_item')->insert([
            'idsite' => 1,
            'idvisit' => 2,
            'idorder' => 'OVERFLOW',
            'idaction_sku' => 25,
            'idaction_name' => 24,
            'price' => 2_000_000_000_000,
            'quantity' => 1,
            'server_time' => '2026-08-15 10:06:00',
        ]);
        $this->connection->table('log_action')->insert([
            'idaction' => 30,
            'name' => '',
            'type' => 5,
        ]);
        $this->connection->table('log_conversion_item')->insert([
            'idsite' => 1,
            'idvisit' => 2,
            'idorder' => 'UNDEFINED',
            'idaction_sku' => 30,
            'idaction_name' => 24,
            'price' => 5,
            'quantity' => 1,
            'server_time' => '2026-08-15 10:07:00',
        ]);
        $this->registerEcommerceItemCollector();

        $this->archiver()->archive(new ArchiveReportRequest(1, 'day', '2026-08-15'));

        $periods = new CarbonReportingPeriodFactory;
        $day = $periods->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $blobs = new DatabaseBlobArchiveRepository($this->connection);
        $skuRows = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$day],
            '',
            'Goals_ItemsSku',
        )[1][$day->rangeKey()]);
        $this->assertSame(99.9, $skuRows['SKU-2']['revenue']);
        $this->assertSame(2, $skuRows['SKU-2']['quantity']);
        $this->assertSame(49.95, $skuRows['SKU-2']['price']);
        $this->assertSame(1, $skuRows['SKU-2']['orders']);
        $this->assertSame(10, $skuRows['OTHER']['revenue']);
        $this->assertSame(10, $skuRows['OTHER']['price']);
        $this->assertSame(2, $skuRows['OTHER']['quantity']);
        $this->assertSame(2, $skuRows['OTHER']['orders']);
        $this->assertSame(5, $skuRows['Value not defined']['revenue']);
        $this->assertSame(1, $skuRows['VIEW-SKU']['nb_uniq_visitors']);
        $this->assertSame(1, $skuRows['VIEW-SKU']['nb_visits']);
        $this->assertSame(1, $skuRows['VIEW-SKU']['nb_actions']);
        $this->assertSame(42.5, $skuRows['VIEW-SKU']['avg_price_viewed']);
        $cartRows = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$day],
            '',
            'Goals_ItemsSku_Cart',
        )[1][$day->rangeKey()]);
        $this->assertSame(60, $cartRows['SKU-2']['revenue']);
        $this->assertSame(3, $cartRows['SKU-2']['quantity']);
        $this->assertSame(20, $cartRows['SKU-2']['price']);
        $this->assertSame(1, $cartRows['SKU-2']['orders']);
        $this->assertSame(42.5, $cartRows['VIEW-SKU']['avg_price_viewed']);
        $categoryRows = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$day],
            '',
            'Goals_ItemsCategory',
        )[1][$day->rangeKey()]);
        $this->assertSame(99.9, $categoryRows['Tools']['revenue']);
        $this->assertSame(99.9, $categoryRows['Sale']['revenue']);
        $this->assertSame(1, $categoryRows['Viewed Tools']['nb_actions']);
        $this->assertSame(1, $categoryRows['Featured']['nb_actions']);

        $segment = 'countryCode==nz';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
            plugin: 'Goals',
        ));
        $segmentRows = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$day],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($segment),
            'Goals_ItemsSku',
        )[1][$day->rangeKey()]);
        $this->assertArrayHasKey('SKU-2', $segmentRows);
        $this->assertArrayNotHasKey('VIEW-SKU', $segmentRows);

        $this->events->listen(ArchiveActionsQueryBuilding::class, static function (
            ArchiveActionsQueryBuilding $event,
        ): void {
            if ($event->request->segment === 'extensionAction==view') {
                $event->query->where('log_link_visit_action.product_price', 42.5);
                $event->segmentApplied = true;
            }
        });
        $this->events->listen(ArchiveVisitsQueryBuilding::class, static function (
            ArchiveVisitsQueryBuilding $event,
        ): void {
            if ($event->request->segment === 'extensionAction==view') {
                $event->query->where('log_visit.idvisit', 2);
                $event->segmentApplied = true;
            }
        });
        $extensionSegment = 'extensionAction==view';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $extensionSegment,
            plugin: 'Goals',
        ));
        $extensionRows = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$day],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($extensionSegment),
            'Goals_ItemsSku',
        )[1][$day->rangeKey()]);
        $this->assertSame(1, $extensionRows['VIEW-SKU']['nb_actions']);

        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            force: true,
        ));
        $week = $periods->make('week', '2026-08-15', 'Pacific/Auckland')[0][0];
        $weekRows = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$week],
            '',
            'Goals_ItemsSku',
        )[1][$week->rangeKey()]);
        $this->assertSame(99.9, $weekRows['SKU-2']['revenue']);
        $this->assertSame(1, $weekRows['VIEW-SKU']['nb_actions']);
    }

    public function test_collects_event_hierarchies_values_segments_and_parent_records(): void
    {
        $this->insertVisits();
        $this->insertActionRows();
        $this->connection->table('log_action')->insert([
            ['idaction' => 30, 'name' => '', 'type' => 12],
            ['idaction' => 31, 'name' => 'pause', 'type' => 11],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_name' => 7,
                'idaction_event_category' => 5,
                'idaction_event_action' => 6,
                'custom_float' => null,
                'server_time' => '2026-08-15 10:10:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idaction_name' => 30,
                'idaction_event_category' => 5,
                'idaction_event_action' => 31,
                'custom_float' => 4,
                'server_time' => '2026-08-14 13:00:00',
            ],
        ]);
        $this->registerEventCollector();

        $this->archiver()->archive(new ArchiveReportRequest(1, 'day', '2026-08-15'));

        $periods = new CarbonReportingPeriodFactory;
        $day = $periods->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $blobs = new DatabaseBlobArchiveRepository($this->connection);
        $records = $blobs->records(
            [1],
            [$day],
            '',
            'Events_category_action',
            true,
        )[1][$day->rangeKey()];
        $categories = $this->hierarchicalRowsByLabel($records['Events_category_action']);
        $video = $categories['Video'];
        $this->assertSame(2, $video['columns']['nb_uniq_visitors']);
        $this->assertSame(2, $video['columns']['nb_visits']);
        $this->assertSame(3, $video['columns']['nb_events']);
        $this->assertSame(2, $video['columns']['nb_events_with_value']);
        $this->assertSame(13.5, $video['columns']['sum_event_value']);
        $this->assertSame(4, $video['columns']['min_event_value']);
        $this->assertSame(9.5, $video['columns']['max_event_value']);
        $this->assertIsInt($video['subtableId']);
        $actions = $this->hierarchicalRowsByLabel(
            $records['Events_category_action_'.$video['subtableId']],
        );
        $this->assertSame(2, $actions['play']['columns']['nb_events']);
        $this->assertSame(1, $actions['play']['columns']['nb_events_with_value']);
        $this->assertSame(9.5, $actions['play']['columns']['sum_event_value']);
        $this->assertSame(1, $actions['pause']['columns']['nb_events']);

        $categoryNames = $blobs->records(
            [1],
            [$day],
            '',
            'Events_category_name',
            true,
        )[1][$day->rangeKey()];
        $videoByName = $this->hierarchicalRowsByLabel(
            $categoryNames['Events_category_name'],
        )['Video'];
        $names = $this->hierarchicalRowsByLabel(
            $categoryNames['Events_category_name_'.$videoByName['subtableId']],
        );
        $this->assertSame(['Trailer'], array_keys($names));
        $this->assertSame(2, $names['Trailer']['columns']['nb_events']);

        $nameActions = $blobs->records(
            [1],
            [$day],
            '',
            'Events_name_action',
            true,
        )[1][$day->rangeKey()];
        $eventNames = $this->hierarchicalRowsByLabel($nameActions['Events_name_action']);
        $this->assertSame(1, $eventNames['Piwik_EventNameNotSet']['columns']['nb_events']);

        $segment = 'countryCode==nz';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
            plugin: 'Events',
        ));
        $segmentRecords = $blobs->records(
            [1],
            [$day],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($segment),
            'Events_category_action',
            true,
        )[1][$day->rangeKey()];
        $segmentVideo = $this->hierarchicalRowsByLabel(
            $segmentRecords['Events_category_action'],
        )['Video'];
        $segmentActions = $this->hierarchicalRowsByLabel(
            $segmentRecords['Events_category_action_'.$segmentVideo['subtableId']],
        );
        $this->assertSame(['pause'], array_keys($segmentActions));

        $this->events->listen(ArchiveActionsQueryBuilding::class, static function (
            ArchiveActionsQueryBuilding $event,
        ): void {
            if ($event->request->segment === 'extensionEvent==valued') {
                $event->query->where('log_link_visit_action.custom_float', 9.5);
                $event->segmentApplied = true;
            }
        });
        $this->events->listen(ArchiveVisitsQueryBuilding::class, static function (
            ArchiveVisitsQueryBuilding $event,
        ): void {
            if ($event->request->segment === 'extensionEvent==valued') {
                $event->query->where('log_visit.idvisit', 2);
                $event->segmentApplied = true;
            }
        });
        $extensionSegment = 'extensionEvent==valued';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $extensionSegment,
            plugin: 'Events',
        ));
        $extensionRecords = $blobs->records(
            [1],
            [$day],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($extensionSegment),
            'Events_category_action',
            true,
        )[1][$day->rangeKey()];
        $extensionVideo = $this->hierarchicalRowsByLabel(
            $extensionRecords['Events_category_action'],
        )['Video'];
        $this->assertSame(1, $extensionVideo['columns']['nb_events']);
        $this->assertSame(9.5, $extensionVideo['columns']['sum_event_value']);

        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            force: true,
        ));
        $week = $periods->make('week', '2026-08-15', 'Pacific/Auckland')[0][0];
        $weekRecords = $blobs->records(
            [1],
            [$week],
            '',
            'Events_category_action',
            true,
        )[1][$week->rangeKey()];
        $weekVideo = $this->hierarchicalRowsByLabel(
            $weekRecords['Events_category_action'],
        )['Video'];
        $this->assertSame(3, $weekVideo['columns']['nb_events']);
        $this->assertSame(4, $weekVideo['columns']['min_event_value']);
        $this->assertSame(9.5, $weekVideo['columns']['max_event_value']);
        $this->assertIsInt($weekVideo['subtableId']);
        $this->assertArrayHasKey(
            'Events_category_action_'.$weekVideo['subtableId'],
            $weekRecords,
        );
    }

    public function test_event_root_and_subtable_archives_use_bounded_others_rows(): void
    {
        $this->insertVisits();
        $actions = [
            ['idaction' => 1000, 'name' => '000-shared', 'type' => 10],
            ['idaction' => 1001, 'name' => 'common', 'type' => 11],
            ['idaction' => 1002, 'name' => 'event', 'type' => 12],
        ];
        $links = [];

        for ($index = 0; $index < 501; $index++) {
            $actions[] = [
                'idaction' => 2000 + $index,
                'name' => 'category'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'type' => 10,
            ];
            $actions[] = [
                'idaction' => 3000 + $index,
                'name' => 'action'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'type' => 11,
            ];
            $links[] = [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_name' => 1002,
                'idaction_event_category' => 2000 + $index,
                'idaction_event_action' => 1001,
                'custom_float' => 1,
                'server_time' => '2026-08-15 10:00:00',
            ];
            $links[] = [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_name' => 1002,
                'idaction_event_category' => 1000,
                'idaction_event_action' => 3000 + $index,
                'custom_float' => 1,
                'server_time' => '2026-08-15 10:00:00',
            ];
        }

        foreach (array_chunk($actions, 100) as $chunk) {
            $this->connection->table('log_action')->insert($chunk);
        }

        foreach (array_chunk($links, 100) as $chunk) {
            $this->connection->table('log_link_visit_action')->insert($chunk);
        }

        $this->registerEventCollector();
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'Events',
        ));
        $day = (new CarbonReportingPeriodFactory)->make(
            'day',
            '2026-08-15',
            'Pacific/Auckland',
        )[0][0];
        $records = (new DatabaseBlobArchiveRepository($this->connection))->records(
            [1],
            [$day],
            '',
            'Events_category_action',
            true,
        )[1][$day->rangeKey()];
        $categories = $this->hierarchicalRowsByLabel($records['Events_category_action']);
        $this->assertCount(500, $categories);
        $this->assertSame(3, $categories[-1]['columns']['nb_events']);
        $this->assertNull($categories[-1]['subtableId']);
        $shared = $categories['000-shared'];
        $this->assertIsInt($shared['subtableId']);
        $sharedActions = $this->hierarchicalRowsByLabel(
            $records['Events_category_action_'.$shared['subtableId']],
        );
        $this->assertCount(500, $sharedActions);
        $this->assertSame(2, $sharedActions[-1]['columns']['nb_events']);
    }

    public function test_collects_content_hierarchies_interactions_segments_and_parents(): void
    {
        $this->insertVisits();
        $this->connection->table('log_action')->insert([
            ['idaction' => 40, 'name' => 'Hero', 'type' => 8],
            ['idaction' => 41, 'name' => 'Banner', 'type' => 13],
            ['idaction' => 42, 'name' => 'https://content.example.test', 'type' => 14],
            ['idaction' => 43, 'name' => 'click', 'type' => 15],
            ['idaction' => 44, 'name' => '', 'type' => 13],
            ['idaction' => 45, 'name' => 'No impression', 'type' => 8],
            ['idaction' => 46, 'name' => 'Ignored piece', 'type' => 13],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_content_name' => 40,
                'idaction_content_piece' => 41,
                'idaction_content_target' => 42,
                'server_time' => '2026-08-15 08:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_content_name' => 40,
                'idaction_content_piece' => 41,
                'idaction_content_target' => 42,
                'server_time' => '2026-08-15 08:05:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idaction_content_name' => 40,
                'idaction_content_piece' => 44,
                'idaction_content_target' => 42,
                'server_time' => '2026-08-14 13:00:00',
            ],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_content_name' => 40,
                'idaction_content_piece' => 41,
                'idaction_content_interaction' => 43,
                'server_time' => '2026-08-15 08:10:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idaction_content_name' => 40,
                'idaction_content_piece' => 44,
                'idaction_content_interaction' => 43,
                'server_time' => '2026-08-14 13:05:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_content_name' => 45,
                'idaction_content_piece' => 46,
                'idaction_content_interaction' => 43,
                'server_time' => '2026-08-15 08:15:00',
            ],
        ]);
        $this->registerContentCollector();

        $this->archiver()->archive(new ArchiveReportRequest(1, 'day', '2026-08-15'));

        $periods = new CarbonReportingPeriodFactory;
        $day = $periods->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $blobs = new DatabaseBlobArchiveRepository($this->connection);
        $nameRecords = $blobs->records(
            [1],
            [$day],
            '',
            'Contents_name_piece',
            true,
        )[1][$day->rangeKey()];
        $names = $this->hierarchicalRowsByLabel($nameRecords['Contents_name_piece']);
        $hero = $names['Hero'];
        $this->assertSame(2, $hero['columns']['nb_uniq_visitors']);
        $this->assertSame(2, $hero['columns']['nb_visits']);
        $this->assertSame(3, $hero['columns']['nb_impressions']);
        $this->assertSame(2, $hero['columns']['nb_interactions']);
        $this->assertIsInt($hero['subtableId']);
        $pieces = $this->hierarchicalRowsByLabel(
            $nameRecords['Contents_name_piece_'.$hero['subtableId']],
        );
        $this->assertSame(['Banner'], array_keys($pieces));
        $this->assertSame(2, $pieces['Banner']['columns']['nb_impressions']);
        $this->assertSame(1, $pieces['Banner']['columns']['nb_interactions']);
        $this->assertArrayNotHasKey('No impression', $names);

        $pieceRecords = $blobs->records(
            [1],
            [$day],
            '',
            'Contents_piece_name',
            true,
        )[1][$day->rangeKey()];
        $contentPieces = $this->hierarchicalRowsByLabel(
            $pieceRecords['Contents_piece_name'],
        );
        $this->assertSame(2, $contentPieces['Banner']['columns']['nb_impressions']);
        $this->assertSame(1, $contentPieces['Banner']['columns']['nb_interactions']);
        $this->assertSame(
            1,
            $contentPieces['Piwik_ContentPieceNotSet']['columns']['nb_impressions'],
        );
        $this->assertSame(
            0,
            $contentPieces['Piwik_ContentPieceNotSet']['columns']['nb_interactions'],
        );

        $segment = 'countryCode==nz';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
            plugin: 'Contents',
        ));
        $segmentRecords = $blobs->records(
            [1],
            [$day],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($segment),
            'Contents_name_piece',
            false,
        )[1][$day->rangeKey()];
        $segmentHero = $this->hierarchicalRowsByLabel(
            $segmentRecords['Contents_name_piece'],
        )['Hero'];
        $this->assertSame(1, $segmentHero['columns']['nb_impressions']);
        $this->assertSame(1, $segmentHero['columns']['nb_interactions']);

        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            force: true,
        ));
        $week = $periods->make('week', '2026-08-15', 'Pacific/Auckland')[0][0];
        $weekRecords = $blobs->records(
            [1],
            [$week],
            '',
            'Contents_name_piece',
            true,
        )[1][$week->rangeKey()];
        $weekHero = $this->hierarchicalRowsByLabel(
            $weekRecords['Contents_name_piece'],
        )['Hero'];
        $this->assertSame(3, $weekHero['columns']['nb_impressions']);
        $this->assertSame(2, $weekHero['columns']['nb_interactions']);
        $this->assertIsInt($weekHero['subtableId']);
        $this->assertArrayHasKey(
            'Contents_name_piece_'.$weekHero['subtableId'],
            $weekRecords,
        );
    }

    public function test_content_subtables_use_the_actions_row_limit(): void
    {
        $this->insertVisits();
        $actions = [
            ['idaction' => 1000, 'name' => 'Shared name', 'type' => 8],
            ['idaction' => 1001, 'name' => 'Target', 'type' => 14],
        ];
        $links = [];

        for ($index = 0; $index < 101; $index++) {
            $actions[] = [
                'idaction' => 2000 + $index,
                'name' => 'piece'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'type' => 13,
            ];
            $links[] = [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_content_name' => 1000,
                'idaction_content_piece' => 2000 + $index,
                'idaction_content_target' => 1001,
                'server_time' => '2026-08-15 10:00:00',
            ];
        }

        foreach (array_chunk($actions, 100) as $chunk) {
            $this->connection->table('log_action')->insert($chunk);
        }

        foreach (array_chunk($links, 100) as $chunk) {
            $this->connection->table('log_link_visit_action')->insert($chunk);
        }

        $this->registerContentCollector();
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'Contents',
        ));
        $day = (new CarbonReportingPeriodFactory)->make(
            'day',
            '2026-08-15',
            'Pacific/Auckland',
        )[0][0];
        $records = (new DatabaseBlobArchiveRepository($this->connection))->records(
            [1],
            [$day],
            '',
            'Contents_name_piece',
            true,
        )[1][$day->rangeKey()];
        $name = $this->hierarchicalRowsByLabel($records['Contents_name_piece'])['Shared name'];
        $this->assertIsInt($name['subtableId']);
        $pieces = $this->hierarchicalRowsByLabel(
            $records['Contents_name_piece_'.$name['subtableId']],
        );
        $this->assertCount(100, $pieces);
        $this->assertSame(2, $pieces[-1]['columns']['nb_impressions']);
    }

    public function test_collects_example_plugin_metrics_state_blobs_segments_and_parents(): void
    {
        $this->insertVisits();
        $this->registerExamplePluginCollector();
        $request = new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'ExamplePlugin',
        );

        $first = $this->archiver()->archive($request);
        $second = $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'ExamplePlugin',
            force: true,
        ));

        $this->assertSame([1], $first->archiveIds);
        $this->assertSame([2], $second->archiveIds);
        $this->assertSame([
            1 => [
                'ExamplePlugin_example_metric' => -3690.0,
                'ExamplePlugin_example_metric2' => 0.0,
            ],
            2 => [
                'ExamplePlugin_example_metric' => -3690.0,
                'ExamplePlugin_example_metric2' => 1.0,
            ],
        ], $this->connection
            ->table('archive_numeric_2026_08')
            ->whereIn('idarchive', [1, 2])
            ->whereIn('name', [
                'ExamplePlugin_example_metric',
                'ExamplePlugin_example_metric2',
            ])
            ->get()
            ->groupBy('idarchive')
            ->map(static fn ($rows): array => $rows->pluck('value', 'name')->all())
            ->all());

        $periods = new CarbonReportingPeriodFactory;
        $day = $periods->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $blobs = new DatabaseBlobArchiveRepository($this->connection);
        $visitors = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$day],
            '',
            'ExamplePlugin_exampleBlob',
        )[1][$day->rangeKey()]);
        $this->assertSame(1, $visitors['visitor-a']['nb_visits']);
        $this->assertSame(1, $visitors['visitor-a']['nb_actions']);
        $this->assertSame(1, $visitors['visitor-b']['nb_visits']);
        $this->assertSame(3, $visitors['visitor-b']['nb_actions']);

        $segment = 'countryCode==nz';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
            plugin: 'ExamplePlugin',
        ));
        $segmentVisitors = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$day],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($segment),
            'ExamplePlugin_exampleBlob',
        )[1][$day->rangeKey()]);
        $this->assertSame(['visitor-a'], array_keys($segmentVisitors));

        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            force: true,
        ));
        $week = $periods->make('week', '2026-08-15', 'Pacific/Auckland')[0][0];
        $weekMetrics = (new DatabaseVisitsSummaryArchiveRepository($this->connection))
            ->pluginMetrics(
                [1],
                [$week],
                '',
                [
                    'ExamplePlugin_example_metric',
                    'ExamplePlugin_example_metric2',
                ],
                'ExamplePlugin',
            )[1][$week->rangeKey()];
        $this->assertSame(-25_816, $weekMetrics['ExamplePlugin_example_metric']);
        $this->assertSame(1, $weekMetrics['ExamplePlugin_example_metric2']);
        $weekVisitors = $this->blobRowsByLabel($blobs->rows(
            [1],
            [$week],
            '',
            'ExamplePlugin_exampleBlob',
        )[1][$week->rangeKey()]);
        $this->assertSame(1, $weekVisitors['visitor-a']['nb_visits']);
        $this->assertSame(1, $weekVisitors['visitor-b']['nb_visits']);
    }

    public function test_collects_page_performance_totals_caps_segments_and_parents(): void
    {
        $this->insertVisits();
        $this->insertActionRows();
        $this->connection->table('log_link_visit_action')->where('idlink_va', 1)->update([
            'time_network' => 100,
            'time_server' => 200,
            'time_transfer' => 300,
            'time_dom_processing' => 400,
            'time_dom_completion' => 500,
            'time_on_load' => 600,
        ]);
        $this->connection->table('log_link_visit_action')->where('idlink_va', 2)->update([
            'time_server' => 2_000,
        ]);
        $this->connection->table('log_link_visit_action')->where('idlink_va', 4)->update([
            'time_network' => 50,
        ]);
        $this->registerPagePerformanceCollector(['time_server' => 1_000]);
        $request = new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'PagePerformance',
        );

        $this->archiver()->archive($request);

        $periods = new CarbonReportingPeriodFactory;
        $day = $periods->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $names = [
            'PagePerformance_network_time',
            'PagePerformance_network_hits',
            'PagePerformance_servery_time',
            'PagePerformance_server_hits',
            'PagePerformance_transfer_time',
            'PagePerformance_transfer_hits',
            'PagePerformance_domprocessing_time',
            'PagePerformance_domprocessing_hits',
            'PagePerformance_domcompletion_time',
            'PagePerformance_domcompletion_hits',
            'PagePerformance_onload_time',
            'PagePerformance_onload_hits',
            'PagePerformance_pageload_time',
            'PagePerformance_pageload_hits',
        ];
        $numbers = new DatabaseVisitsSummaryArchiveRepository($this->connection);
        $dayMetrics = $numbers->pluginMetrics(
            [1],
            [$day],
            '',
            $names,
            'PagePerformance',
        )[1][$day->rangeKey()];
        $expected = [
            'PagePerformance_network_time' => 150,
            'PagePerformance_network_hits' => 2,
            'PagePerformance_servery_time' => 1_200,
            'PagePerformance_server_hits' => 2,
            'PagePerformance_transfer_time' => 300,
            'PagePerformance_transfer_hits' => 1,
            'PagePerformance_domprocessing_time' => 400,
            'PagePerformance_domprocessing_hits' => 1,
            'PagePerformance_domcompletion_time' => 500,
            'PagePerformance_domcompletion_hits' => 1,
            'PagePerformance_onload_time' => 600,
            'PagePerformance_onload_hits' => 1,
            'PagePerformance_pageload_time' => 3_150,
            'PagePerformance_pageload_hits' => 3,
        ];
        ksort($expected);
        ksort($dayMetrics);
        $this->assertSame($expected, $dayMetrics);

        $segment = 'countryCode==au';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
            reports: ['PagePerformance.get'],
        ));
        $segmentHash = (new DatabaseSegmentHashResolver($this->connection))->resolve($segment);
        $segmentMetrics = $numbers->pluginMetrics(
            [1],
            [$day],
            $segmentHash,
            $names,
            'PagePerformance',
        )[1][$day->rangeKey()];
        $this->assertSame($dayMetrics, $segmentMetrics);

        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            plugin: 'PagePerformance',
            force: true,
        ));
        $week = $periods->make('week', '2026-08-15', 'Pacific/Auckland')[0][0];
        $weekMetrics = $numbers->pluginMetrics(
            [1],
            [$week],
            '',
            $names,
            'PagePerformance',
        )[1][$week->rangeKey()];
        $this->assertSame($dayMetrics, $weekMetrics);
    }

    public function test_collects_recursive_action_records_metrics_extensions_segments_and_parents(): void
    {
        $this->insertVisits();
        $this->insertActionArchiveRows();
        $this->connection->table('site')->where('idsite', 1)->update([
            'main_url' => 'https://example.test',
            'sitesearch' => 1,
        ]);
        $this->connection->table('log_visit')->where('idvisit', 1)->update([
            'visit_entry_idaction_url' => 30,
            'visit_entry_idaction_name' => 31,
            'visit_exit_idaction_url' => 32,
            'visit_exit_idaction_name' => 33,
            'visit_total_actions' => 4,
            'visit_total_time' => 90,
        ]);
        $this->connection->table('log_visit')->where('idvisit', 2)->update([
            'visit_entry_idaction_url' => 37,
            'visit_entry_idaction_name' => 36,
            'visit_exit_idaction_url' => 37,
            'visit_exit_idaction_name' => 38,
            'visit_total_actions' => 2,
            'visit_total_time' => 40,
        ]);
        $this->events->listen(
            ActionArchiveMetricsCollecting::class,
            static function (ActionArchiveMetricsCollecting $event): void {
                $event->metrics[] = new ActionArchiveMetric(
                    'extension_hits',
                    new TrustedSegmentSqlExpression('COUNT(*) * 2'),
                );
            },
        );
        $this->registerActionCollector(new ActionArchiveConfiguration(flatLimit: 500));
        $request = new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            plugin: 'Actions',
        );

        $this->archiver()->archive($request);

        $periods = new CarbonReportingPeriodFactory;
        $day = $periods->make('day', '2026-08-15', 'Pacific/Auckland')[0][0];
        $blobs = new DatabaseBlobArchiveRepository($this->connection);
        $urlRecords = $blobs->records(
            [1],
            [$day],
            '',
            'Actions_actions_url',
            true,
        )[1][$day->rangeKey()];
        $urls = $this->hierarchicalRowsByLabel($urlRecords['Actions_actions_url']);
        $this->assertSame(2, $urls['docs']['columns']['nb_visits']);
        $this->assertSame(2, $urls['docs']['columns']['nb_hits']);
        $this->assertArrayNotHasKey('nb_uniq_visitors', $urls['docs']['columns']);
        $docsSubtable = $urls['docs']['subtableId'];
        $this->assertIsInt($docsSubtable);
        $docs = $this->hierarchicalRowsByLabel(
            $urlRecords['Actions_actions_url_'.$docsSubtable],
        );
        $this->assertSame(30, $docs['/start']['columns']['sum_time_spent']);
        $this->assertSame(1, $docs['/start']['columns']['entry_nb_visits']);
        $this->assertSame(1, $docs['/next']['columns']['exit_nb_visits']);
        $this->assertSame(2, $docs['/start']['columns']['extension_hits']);
        $this->assertSame(
            'https://example.test/docs/start',
            $docs['/start']['metadata']['url'],
        );
        $this->assertSame(1, $urls['/results']['columns']['nb_hits_following_search']);

        $downloads = $this->hierarchicalRowsByLabel($blobs->records(
            [1],
            [$day],
            '',
            'Actions_downloads',
            true,
        )[1][$day->rangeKey()]['Actions_downloads']);
        $this->assertSame(['cdn.test'], array_keys($downloads));
        $searches = $this->hierarchicalRowsByLabel($blobs->records(
            [1],
            [$day],
            '',
            'Actions_sitesearch',
            false,
        )[1][$day->rangeKey()]['Actions_sitesearch']);
        $this->assertSame(1, $searches['manual']['columns']['site_search_has_no_result']);
        $categories = $this->hierarchicalRowsByLabel($blobs->records(
            [1],
            [$day],
            '',
            'Actions_SiteSearchCategories',
            false,
        )[1][$day->rangeKey()]['Actions_SiteSearchCategories']);
        $this->assertSame(1, $categories['docs']['columns']['nb_actions']);
        $flatUrls = $this->hierarchicalRowsByLabel($blobs->records(
            [1],
            [$day],
            '',
            'Actions_actions_url_flat',
            false,
        )[1][$day->rangeKey()]['Actions_actions_url_flat']);
        $this->assertArrayHasKey('/docs/start', $flatUrls);

        $names = [
            'Actions_nb_searches',
            'Actions_nb_keywords',
            'Actions_nb_outlinks',
            'Actions_nb_uniq_outlinks',
            'Actions_nb_pageviews',
            'Actions_nb_uniq_pageviews',
            'Actions_sum_time_generation',
            'Actions_nb_hits_with_time_generation',
            'Actions_nb_downloads',
            'Actions_nb_uniq_downloads',
            'Actions_hits',
        ];
        $numbers = new DatabaseVisitsSummaryArchiveRepository($this->connection);
        $dayMetrics = $numbers->pluginMetrics(
            [1],
            [$day],
            '',
            $names,
            'Actions',
        )[1][$day->rangeKey()];
        $expectedMetrics = [
            'Actions_nb_downloads' => 1,
            'Actions_nb_hits_with_time_generation' => 3,
            'Actions_nb_keywords' => 1,
            'Actions_nb_outlinks' => 1,
            'Actions_nb_pageviews' => 4,
            'Actions_nb_searches' => 1,
            'Actions_nb_uniq_downloads' => 1,
            'Actions_nb_uniq_outlinks' => 1,
            'Actions_nb_uniq_pageviews' => 3,
            'Actions_sum_time_generation' => 3.5,
            'Actions_hits' => 6,
        ];
        ksort($dayMetrics);
        ksort($expectedMetrics);
        $this->assertSame($expectedMetrics, $dayMetrics);

        $segment = 'countryCode==au';
        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'day',
            date: '2026-08-15',
            segment: $segment,
            reports: ['Actions.getPageUrls'],
        ));
        $segmentMetrics = $numbers->pluginMetrics(
            [1],
            [$day],
            (new DatabaseSegmentHashResolver($this->connection))->resolve($segment),
            ['Actions_hits', 'Actions_nb_pageviews', 'Actions_nb_searches'],
            'Actions',
        )[1][$day->rangeKey()];
        $this->assertSame([
            'Actions_hits' => 2,
            'Actions_nb_pageviews' => 2,
            'Actions_nb_searches' => 1,
        ], $segmentMetrics);

        $this->archiver()->archive(new ArchiveReportRequest(
            siteId: 1,
            period: 'week',
            date: '2026-08-15',
            plugin: 'Actions',
            force: true,
        ));
        $week = $periods->make('week', '2026-08-15', 'Pacific/Auckland')[0][0];
        $weekMetrics = $numbers->pluginMetrics(
            [1],
            [$week],
            '',
            $names,
            'Actions',
        )[1][$week->rangeKey()];
        ksort($weekMetrics);
        $this->assertSame($expectedMetrics, $weekMetrics);
        $weekUrls = $this->hierarchicalRowsByLabel($blobs->records(
            [1],
            [$week],
            '',
            'Actions_actions_url',
            true,
        )[1][$week->rangeKey()]['Actions_actions_url']);
        $this->assertSame(2, $weekUrls['docs']['columns']['nb_visits']);
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
            visitQueries: new ArchiveVisitQueryFactory(
                $this->connection,
                $this->visitSegmentApplicator(),
                $this->events,
            ),
            events: $this->events,
        );
    }

    private function registerVisitDimensionCollector(): void
    {
        $visits = $this->visitSegmentApplicator();
        $collector = new VisitDimensionArchiveCollector(
            connection: $this->connection,
            visitQueries: new ArchiveVisitQueryFactory($this->connection, $visits, $this->events),
            conversionQueries: new ArchiveConversionQueryFactory($this->connection, $visits, $this->events),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
            browserLanguages: new BrowserLanguageArchiveLabeler(
                languageCodes: ['en', 'fr'],
                countryCodes: ['fr', 'us'],
                countriesByLanguage: ['fr' => 'fr'],
            ),
        );
        $this->events->listen(ArchiveReportsCollecting::class, $collector);
        $this->events->listen(ArchiveReportsCollecting::class, new VisitAggregateArchiveCollector(
            connection: $this->connection,
            visitQueries: new ArchiveVisitQueryFactory($this->connection, $visits, $this->events),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
        ));
    }

    private function registerGoalCollector(): void
    {
        $visits = $this->visitSegmentApplicator();
        $this->events->listen(ArchiveReportsCollecting::class, new GoalArchiveCollector(
            connection: $this->connection,
            conversionQueries: new ArchiveConversionQueryFactory(
                $this->connection,
                $visits,
                $this->events,
            ),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            numbers: new DatabaseVisitsSummaryArchiveRepository($this->connection),
            goals: new DatabaseGoalRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
        ));
    }

    private function registerEcommerceItemCollector(): void
    {
        $this->events->listen(ArchiveReportsCollecting::class, new EcommerceItemArchiveCollector(
            connection: $this->connection,
            actionQueries: new ArchiveActionQueryFactory(
                $this->connection,
                $this->visitSegmentApplicator(),
                $this->events,
            ),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
        ));
    }

    private function registerEventCollector(): void
    {
        $this->events->listen(ArchiveReportsCollecting::class, new EventArchiveCollector(
            connection: $this->connection,
            actionQueries: new ArchiveActionQueryFactory(
                $this->connection,
                $this->visitSegmentApplicator(),
                $this->events,
            ),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
        ));
    }

    private function registerContentCollector(): void
    {
        $this->events->listen(ArchiveReportsCollecting::class, new ContentArchiveCollector(
            connection: $this->connection,
            actionQueries: new ArchiveActionQueryFactory(
                $this->connection,
                $this->visitSegmentApplicator(),
                $this->events,
            ),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
        ));
    }

    private function registerExamplePluginCollector(): void
    {
        $this->events->listen(ArchiveReportsCollecting::class, new ExamplePluginArchiveCollector(
            connection: $this->connection,
            visitQueries: new ArchiveVisitQueryFactory(
                $this->connection,
                $this->visitSegmentApplicator(),
                $this->events,
            ),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            numbers: new DatabaseVisitsSummaryArchiveRepository($this->connection),
            options: new DatabaseOptionRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
        ));
    }

    /** @param array<string, int> $caps */
    private function registerPagePerformanceCollector(array $caps = []): void
    {
        $this->events->listen(ArchiveReportsCollecting::class, new PagePerformanceArchiveCollector(
            connection: $this->connection,
            actionQueries: new ArchiveActionQueryFactory(
                $this->connection,
                $this->visitSegmentApplicator(),
                $this->events,
            ),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            numbers: new DatabaseVisitsSummaryArchiveRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
            caps: $caps,
        ));
    }

    private function registerActionCollector(ActionArchiveConfiguration $configuration): void
    {
        $visits = $this->visitSegmentApplicator();
        $this->events->listen(ArchiveReportsCollecting::class, new ActionArchiveCollector(
            connection: $this->connection,
            actionQueries: new ArchiveActionQueryFactory($this->connection, $visits, $this->events),
            visitQueries: new ArchiveVisitQueryFactory($this->connection, $visits, $this->events),
            subperiods: new CarbonReportingSubperiodFactory,
            segments: new DatabaseSegmentHashResolver($this->connection),
            blobs: new DatabaseBlobArchiveRepository($this->connection),
            numbers: new DatabaseVisitsSummaryArchiveRepository($this->connection),
            sites: new DatabaseSiteRepository($this->connection),
            configuration: $configuration,
            paths: new ActionArchivePathResolver($configuration),
            events: $this->events,
        ));
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>  $rows
     * @return array<int|string, array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>
     */
    private function hierarchicalRowsByLabel(array $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            $label = $row['columns']['label'] ?? null;

            if (is_float($label) || is_int($label) || is_string($label)) {
                $values[(string) $label] = $row;
            }
        }

        return $values;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return array<string, array<string, float|int|string|null>>
     */
    private function blobRowsByLabel(array $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            $label = $row['columns']['label'] ?? null;

            if (is_float($label) || is_int($label) || is_string($label)) {
                $values[(string) $label] = $row['columns'];
            }
        }

        return $values;
    }

    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     * @return array<string, int|float>
     */
    private function rangeValues(array $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            $label = $row['columns']['label'] ?? null;
            $conversions = $row['columns']['nb_conversions'] ?? null;

            if (is_string($label) && (is_float($conversions) || is_int($conversions))) {
                $values[$label] = $conversions;
            }
        }

        return $values;
    }

    private function visitSegmentApplicator(): BuiltInVisitSegmentApplicator
    {
        return new BuiltInVisitSegmentApplicator(
            new SegmentExpressionParser,
            new SegmentConditionQueryApplier(
                $this->app->make(CountryMetadataProvider::class),
            ),
            new DynamicSegmentResolver,
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
            ['idaction' => 10, 'name' => 'Viewed Widget', 'type' => 6],
            ['idaction' => 11, 'name' => 'VIEW-SKU', 'type' => 5],
            ['idaction' => 12, 'name' => 'Viewed Tools', 'type' => 7],
            ['idaction' => 13, 'name' => 'Featured', 'type' => 7],
            ['idaction' => 20, 'name' => 'Widget', 'type' => 6],
            ['idaction' => 21, 'name' => 'SKU-2', 'type' => 5],
            ['idaction' => 22, 'name' => 'Tools', 'type' => 7],
            ['idaction' => 23, 'name' => 'Sale', 'type' => 7],
            ['idaction' => 24, 'name' => 'Spare', 'type' => 6],
            ['idaction' => 25, 'name' => 'OTHER', 'type' => 5],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 1,
                'idaction_name' => 2,
                'idaction_event_category' => null,
                'idaction_event_action' => null,
                'idaction_product_name' => 10,
                'idaction_product_sku' => 11,
                'idaction_product_cat' => 12,
                'idaction_product_cat2' => 13,
                'search_cat' => null,
                'search_count' => null,
                'custom_float' => null,
                'product_price' => 42.5,
                'server_time' => '2026-08-15 08:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 3,
                'idaction_name' => 4,
                'idaction_event_category' => null,
                'idaction_event_action' => null,
                'idaction_product_name' => null,
                'idaction_product_sku' => null,
                'idaction_product_cat' => null,
                'idaction_product_cat2' => null,
                'search_cat' => 'docs',
                'search_count' => 2,
                'custom_float' => null,
                'product_price' => null,
                'server_time' => '2026-08-15 09:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 8,
                'idaction_name' => 7,
                'idaction_event_category' => 5,
                'idaction_event_action' => 6,
                'idaction_product_name' => null,
                'idaction_product_sku' => null,
                'idaction_product_cat' => null,
                'idaction_product_cat2' => null,
                'search_cat' => null,
                'search_count' => null,
                'custom_float' => 9.5,
                'product_price' => null,
                'server_time' => '2026-08-15 10:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 1,
                'idaction_name' => 9,
                'idaction_event_category' => null,
                'idaction_event_action' => null,
                'idaction_product_name' => null,
                'idaction_product_sku' => null,
                'idaction_product_cat' => null,
                'idaction_product_cat2' => null,
                'search_cat' => null,
                'search_count' => null,
                'custom_float' => null,
                'product_price' => null,
                'server_time' => '2026-08-15 11:00:00',
            ],
        ]);
    }

    private function insertActionArchiveRows(): void
    {
        $this->connection->table('log_action')->insert([
            ['idaction' => 30, 'name' => 'example.test/docs/start', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 31, 'name' => 'Docs / Start', 'type' => 4, 'url_prefix' => null],
            ['idaction' => 32, 'name' => 'example.test/docs/next', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 33, 'name' => 'Docs / Next', 'type' => 4, 'url_prefix' => null],
            ['idaction' => 34, 'name' => 'https://external.test/path', 'type' => 2, 'url_prefix' => null],
            ['idaction' => 35, 'name' => 'https://cdn.test/file.zip', 'type' => 3, 'url_prefix' => null],
            ['idaction' => 36, 'name' => 'manual', 'type' => 8, 'url_prefix' => null],
            ['idaction' => 37, 'name' => 'example.test/results', 'type' => 1, 'url_prefix' => 2],
            ['idaction' => 38, 'name' => 'Results', 'type' => 4, 'url_prefix' => null],
        ]);
        $this->connection->table('log_link_visit_action')->insert([
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idaction_url' => 30,
                'idaction_name' => 31,
                'idaction_url_ref' => null,
                'idaction_name_ref' => null,
                'idaction_event_category' => null,
                'custom_float' => 1_000,
                'search_count' => null,
                'search_cat' => null,
                'time_spent_ref_action' => 0,
                'server_time' => '2026-08-14 13:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idaction_url' => 32,
                'idaction_name' => 33,
                'idaction_url_ref' => 30,
                'idaction_name_ref' => 31,
                'idaction_event_category' => null,
                'custom_float' => 2_000,
                'search_count' => null,
                'search_cat' => null,
                'time_spent_ref_action' => 30,
                'server_time' => '2026-08-14 13:01:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idaction_url' => 34,
                'idaction_name' => null,
                'idaction_url_ref' => 32,
                'idaction_name_ref' => 33,
                'idaction_event_category' => null,
                'custom_float' => null,
                'search_count' => null,
                'search_cat' => null,
                'time_spent_ref_action' => 20,
                'server_time' => '2026-08-14 13:02:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 1,
                'idaction_url' => 35,
                'idaction_name' => null,
                'idaction_url_ref' => 34,
                'idaction_name_ref' => null,
                'idaction_event_category' => null,
                'custom_float' => null,
                'search_count' => null,
                'search_cat' => null,
                'time_spent_ref_action' => 10,
                'server_time' => '2026-08-14 13:03:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 37,
                'idaction_name' => 36,
                'idaction_url_ref' => null,
                'idaction_name_ref' => null,
                'idaction_event_category' => null,
                'custom_float' => null,
                'search_count' => 0,
                'search_cat' => 'docs',
                'time_spent_ref_action' => 0,
                'server_time' => '2026-08-15 08:00:00',
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idaction_url' => 37,
                'idaction_name' => 38,
                'idaction_url_ref' => 37,
                'idaction_name_ref' => 36,
                'idaction_event_category' => null,
                'custom_float' => 500,
                'search_count' => null,
                'search_cat' => null,
                'time_spent_ref_action' => 15,
                'server_time' => '2026-08-15 08:01:00',
            ],
        ]);
    }

    private function insertConversionRows(): void
    {
        $this->connection->table('goal')->insert([
            ['idsite' => 1, 'idgoal' => 1, 'name' => 'Newsletter'],
            ['idsite' => 1, 'idgoal' => 2, 'name' => 'Upgrade'],
            ['idsite' => 2, 'idgoal' => 1, 'name' => 'Other Site Goal'],
        ]);
        $this->connection->table('log_conversion')->insert([
            ['idsite' => 1, 'idvisit' => 1, 'idgoal' => 1, 'idorder' => null, 'revenue' => 50, 'server_time' => '2026-08-14 13:00:00'],
            ['idsite' => 1, 'idvisit' => 2, 'idgoal' => 0, 'idorder' => 'ORDER-2', 'revenue' => 125, 'server_time' => '2026-08-15 10:00:00'],
            ['idsite' => 1, 'idvisit' => 2, 'idgoal' => -1, 'idorder' => null, 'revenue' => 45, 'server_time' => '2026-08-15 10:05:00'],
            ['idsite' => 1, 'idvisit' => 2, 'idgoal' => 2, 'idorder' => null, 'revenue' => 10, 'server_time' => '2026-08-15 10:10:00'],
        ]);
        $this->connection->table('log_conversion_item')->insert([
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idorder' => 'ORDER-2',
                'idaction_sku' => 21,
                'idaction_name' => 20,
                'idaction_category' => 22,
                'idaction_category2' => 23,
                'price' => 49.95,
            ],
            [
                'idsite' => 1,
                'idvisit' => 2,
                'idorder' => 'ORDER-2',
                'idaction_sku' => 25,
                'idaction_name' => 24,
                'idaction_category' => null,
                'idaction_category2' => null,
                'price' => 10,
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
