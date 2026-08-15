<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\VisitSegmentApplicator;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Live\DatabaseLiveAccessPolicy;
use App\Matomo\Live\DatabaseLiveCounterRepository;
use App\Matomo\Live\DatabaseLiveVisitorIdentityRepository;
use App\Matomo\Live\DatabaseLiveVisitRepository;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Reporting\DeviceDetectionMetadata;
use App\Matomo\Reporting\DurationFormatter;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseLiveServicesTest extends TestCase
{
    public function test_policy_reads_log_and_profile_site_settings(): void
    {
        $connection = $this->connection();
        $connection->table('site_setting')->insert([
            ['idsite' => 2, 'plugin_name' => 'Live', 'setting_name' => 'disable_visitor_profile', 'setting_value' => '1'],
            ['idsite' => 7, 'plugin_name' => 'Live', 'setting_name' => 'disable_visitor_log', 'setting_value' => '1'],
        ]);
        $policy = new DatabaseLiveAccessPolicy($connection);

        $this->assertTrue($policy->visitorLogEnabled(2));
        $this->assertFalse($policy->visitorProfileEnabled(2));
        $this->assertFalse($policy->visitorLogEnabled(7));
        $this->assertFalse($policy->visitorProfileEnabled(7));
        $this->assertTrue($policy->visitorProfileEnabled(9));
    }

    public function test_counters_use_table_timestamps_and_segment_matched_visits(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 12:00:00');
        $connection = $this->connection();
        $connection->table('log_visit')->insert([
            ['idvisit' => 1, 'idsite' => 2, 'idvisitor' => 'a', 'user_id' => 'person', 'visit_last_action_time' => '2026-08-15 11:50:00'],
            ['idvisit' => 2, 'idsite' => 2, 'idvisitor' => 'a', 'user_id' => 'person', 'visit_last_action_time' => '2026-08-15 11:45:00'],
            ['idvisit' => 3, 'idsite' => 2, 'idvisitor' => 'b', 'user_id' => 'other', 'visit_last_action_time' => '2026-08-15 11:55:00'],
            ['idvisit' => 4, 'idsite' => 2, 'idvisitor' => 'c', 'user_id' => 'person', 'visit_last_action_time' => '2026-08-15 10:00:00'],
        ]);
        $connection->table('log_link_visit_action')->insert([
            ['idvisit' => 1, 'idsite' => 2, 'server_time' => '2026-08-15 11:51:00'],
            ['idvisit' => 2, 'idsite' => 2, 'server_time' => '2026-08-15 11:46:00'],
            ['idvisit' => 4, 'idsite' => 2, 'server_time' => '2026-08-15 10:01:00'],
        ]);
        $connection->table('log_conversion')->insert([
            ['idvisit' => 1, 'idsite' => 2, 'server_time' => '2026-08-15 11:52:00'],
            ['idvisit' => 1, 'idsite' => 2, 'server_time' => '2026-08-15 11:53:00'],
        ]);
        $segments = $this->createStub(VisitSegmentApplicator::class);
        $segments->method('apply')->willReturnCallback(static function (Builder $query, ?string $segment): bool {
            if ($segment !== null) {
                $query->where('log_visit.user_id', 'person');
            }

            return true;
        });
        $repository = new DatabaseLiveCounterRepository($connection, $segments);

        $this->assertSame([
            'visits' => 2,
            'actions' => 2,
            'visitors' => 1,
            'visitsConverted' => 2,
        ], $repository->counters([2], 30, 'userId==person'));
    }

    public function test_visitor_identity_queries_are_ordered_bounded_and_hex_encoded(): void
    {
        $connection = $this->connection();
        $connection->table('log_visit')->insert([
            ['idvisit' => 1, 'idsite' => 2, 'idvisitor' => "\x01\x02", 'user_id' => 'person', 'visit_last_action_time' => '2026-08-14 10:00:00'],
            ['idvisit' => 2, 'idsite' => 2, 'idvisitor' => "\x03\x04", 'user_id' => 'other', 'visit_last_action_time' => '2026-08-15 10:00:00'],
            ['idvisit' => 3, 'idsite' => 7, 'idvisitor' => "\x05\x06", 'user_id' => 'person', 'visit_last_action_time' => '2026-08-16 10:00:00'],
        ]);
        $segments = $this->createStub(VisitSegmentApplicator::class);
        $segments->method('apply')->willReturnCallback(static function (Builder $query, ?string $segment): bool {
            if ($segment !== null) {
                $query->where('log_visit.user_id', 'person');
            }

            return true;
        });
        $repository = new DatabaseLiveVisitorIdentityRepository($connection, $segments);

        $this->assertSame('0102', $repository->mostRecentVisitorId(2, 'userId==person'));
        $this->assertSame(
            '2026-08-15 10:00:00',
            $repository->mostRecentVisitDateTime([2, 7], '2026-08-14 12:00:00', '2026-08-15 23:59:59'),
        );
        $this->assertSame(
            '0102',
            $repository->adjacentVisitorId(2, '0304', '2026-08-15 10:00:00', null, true),
        );
        $this->assertFalse(
            $repository->adjacentVisitorId(2, '0304', '2026-08-15 10:00:00', null, false),
        );
    }

    public function test_visit_details_are_normalized_and_actions_are_batched(): void
    {
        $connection = $this->connection();
        $connection->table('log_visit')->insert([
            'idvisit' => 9, 'idsite' => 2, 'idvisitor' => "\x01\x02", 'location_ip' => inet_pton('127.0.0.1'),
            'user_id' => 'person', 'visit_first_action_time' => '2026-08-15 09:59:00',
            'visit_last_action_time' => '2026-08-15 10:00:00', 'visit_total_time' => 60,
            'visit_total_actions' => 1, 'visitor_returning' => 1,
        ]);
        $connection->table('log_action')->insert([
            ['idaction' => 1, 'type' => 1, 'name' => 'https://example.test/page'],
            ['idaction' => 2, 'type' => 4, 'name' => 'Page title'],
        ]);
        $connection->table('log_link_visit_action')->insert([
            'idvisit' => 9, 'idsite' => 2, 'server_time' => '2026-08-15 10:00:00',
            'idlink_va' => 3, 'idaction_url' => 1, 'idaction_name' => 2,
        ]);
        $segments = $this->createStub(VisitSegmentApplicator::class);
        $segments->method('apply')->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn(['name' => 'Site', 'currency' => 'USD']);
        $translator = $this->createStub(MatomoTranslator::class);
        $translator->method('translate')->willReturn('Unknown');
        $devices = new DeviceDetectionMetadata($translator, dirname(__DIR__, 4));
        $countries = $this->createStub(CountryMetadataProvider::class);
        $currencies = $this->createStub(CurrencyProvider::class);
        $currencies->method('symbols')->willReturn(['USD' => '$']);
        $repository = new DatabaseLiveVisitRepository(
            $connection, $segments, $sites, new DurationFormatter, $devices, $countries, $currencies, $translator,
        );

        $rows = $repository->visits([2], null, null, null, null, 0, 10);

        $this->assertSame(9, $rows[0]['idVisit']);
        $this->assertSame('0102', $rows[0]['visitorId']);
        $this->assertSame('returning', $rows[0]['visitorType']);
        $this->assertSame('https://example.test/page', $rows[0]['actionDetails'][0]['url']);
        $this->assertSame('Page title', $rows[0]['actionDetails'][0]['pageTitle']);
    }

    private function connection(): Connection
    {
        $connection = $this->app->make('db')->connection();
        $schema = $connection->getSchemaBuilder();
        foreach (['site_setting', 'log_visit', 'log_link_visit_action', 'log_conversion', 'log_action'] as $name) {
            $schema->dropIfExists($name);
        }

        $schema->create('site_setting', static function (Blueprint $table): void {
            $table->integer('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->string('setting_value')->nullable();
        });
        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->integer('idvisit')->primary();
            $table->integer('idsite');
            $table->binary('idvisitor');
            $table->string('user_id')->nullable();
            $table->binary('location_ip')->nullable();
            $table->dateTime('visit_first_action_time')->nullable();
            $table->dateTime('visit_last_action_time');
            $table->integer('visit_total_time')->nullable();
            $table->integer('visit_total_actions')->nullable();
            $table->integer('visitor_returning')->nullable();
        });
        foreach (['log_link_visit_action', 'log_conversion'] as $name) {
            $schema->create($name, static function (Blueprint $table): void {
                $table->increments('row_id');
                $table->integer('idvisit');
                $table->integer('idsite');
                $table->dateTime('server_time');
                $table->integer('idlink_va')->nullable();
                $table->integer('idpageview')->nullable();
                $table->integer('idaction_url')->nullable();
                $table->integer('idaction_name')->nullable();
                $table->integer('idaction_event_category')->nullable();
                $table->integer('idaction_event_action')->nullable();
                $table->integer('time_spent_ref_action')->nullable();
                $table->float('custom_float')->nullable();
                $table->string('search_cat')->nullable();
                $table->integer('search_count')->nullable();
            });
        }

        $schema->create('log_action', static function (Blueprint $table): void {
            $table->integer('idaction')->primary();
            $table->integer('type');
            $table->string('name');
            $table->integer('url_prefix')->nullable();
        });

        return $connection;
    }
}
