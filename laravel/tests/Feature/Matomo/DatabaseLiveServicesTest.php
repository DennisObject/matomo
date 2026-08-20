<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Archiving\VisitSegmentApplicator;
use App\Matomo\Live\DatabaseLiveAccessPolicy;
use App\Matomo\Live\DatabaseLiveCounterRepository;
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
        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'Live',
            'user_login' => '',
            'setting_name' => 'disable_visitor_profile',
            'setting_value' => '1',
        ]);
        $policy = new DatabaseLiveAccessPolicy($connection);

        $this->assertTrue($policy->visitorLogEnabled(2));
        $this->assertFalse($policy->visitorProfileEnabled(2));
        $this->assertFalse($policy->visitorLogEnabled(7));
        $this->assertFalse($policy->visitorProfileEnabled(7));
        $this->assertFalse($policy->visitorProfileEnabled(9));
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

    private function connection(): Connection
    {
        $connection = $this->app->make('db')->connection();
        $schema = $connection->getSchemaBuilder();
        foreach (['site_setting', 'plugin_setting', 'log_visit', 'log_link_visit_action', 'log_conversion'] as $name) {
            $schema->dropIfExists($name);
        }

        $schema->create('site_setting', static function (Blueprint $table): void {
            $table->integer('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->string('setting_value')->nullable();
        });
        $schema->create('plugin_setting', static function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login')->default('');
            $table->string('setting_name');
            $table->string('setting_value')->nullable();
        });
        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->integer('idvisit')->primary();
            $table->integer('idsite');
            $table->binary('idvisitor');
            $table->string('user_id')->nullable();
            $table->dateTime('visit_last_action_time');
        });
        foreach (['log_link_visit_action', 'log_conversion'] as $name) {
            $schema->create($name, static function (Blueprint $table): void {
                $table->increments('row_id');
                $table->integer('idvisit');
                $table->integer('idsite');
                $table->dateTime('server_time');
            });
        }

        return $connection;
    }
}
