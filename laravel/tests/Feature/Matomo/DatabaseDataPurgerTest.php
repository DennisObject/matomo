<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Options\OptionRepository;
use App\Matomo\Privacy\DatabaseDataPurger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Tests\TestCase;

final class DatabaseDataPurgerTest extends TestCase
{
    public function test_purge_removes_old_visit_graph_unused_actions_and_old_archives(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 12:00:00');
        $connection = $this->app->make('db')->connection();
        $this->tables($connection);
        $connection->table('log_visit')->insert([
            ['idvisit' => 1, 'visit_last_action_time' => '2025-01-01 00:00:00'],
            ['idvisit' => 2, 'visit_last_action_time' => '2026-08-14 00:00:00'],
        ]);
        $connection->table('log_link_visit_action')->insert([
            ['idlink_va' => 11, 'idvisit' => 1, 'idaction_url' => 101],
            ['idlink_va' => 12, 'idvisit' => 2, 'idaction_url' => 102],
        ]);
        $connection->table('log_conversion')->insert(['idvisit' => 1]);
        $connection->table('log_conversion_item')->insert(['idvisit' => 1]);
        $connection->table('log_action')->insert([
            ['idaction' => 101, 'name' => 'old'],
            ['idaction' => 102, 'name' => 'current'],
            ['idaction' => 103, 'name' => 'unused'],
        ]);

        $options = $this->createStub(OptionRepository::class);
        $options->method('value')->willReturnMap([
            ['delete_logs_enable', '1'],
            ['delete_logs_older_than', '30'],
            ['delete_reports_enable', '1'],
            ['delete_reports_older_than', '3'],
        ]);
        $purger = new DatabaseDataPurger($connection, $options, new Dispatcher($this->app));

        $purger->purge();

        $this->assertSame([2], $connection->table('log_visit')->pluck('idvisit')->all());
        $this->assertSame([12], $connection->table('log_link_visit_action')->pluck('idlink_va')->all());
        $this->assertSame([102], $connection->table('log_action')->pluck('idaction')->all());
        $this->assertFalse($connection->getSchemaBuilder()->hasTable('archive_numeric_2020_01'));
        $this->assertFalse($connection->getSchemaBuilder()->hasTable('archive_blob_2020_01'));
    }

    private function tables(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        foreach ([
            'log_visit', 'log_link_visit_action', 'log_conversion', 'log_conversion_item',
            'log_action', 'archive_numeric_2020_01', 'archive_blob_2020_01',
        ] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->integer('idvisit')->primary();
            $table->dateTime('visit_last_action_time');
        });
        $schema->create('log_link_visit_action', static function (Blueprint $table): void {
            $table->integer('idlink_va')->primary();
            $table->integer('idvisit');
            $table->integer('idaction_url')->nullable();
        });
        foreach (['log_conversion', 'log_conversion_item'] as $name) {
            $schema->create($name, static function (Blueprint $table): void {
                $table->increments('row_id');
                $table->integer('idvisit');
            });
        }

        $schema->create('log_action', static function (Blueprint $table): void {
            $table->integer('idaction')->primary();
            $table->string('name');
        });
        $schema->create('archive_numeric_2020_01', static function (Blueprint $table): void {
            $table->integer('idarchive');
            $table->integer('period');
            $table->string('name');
        });
        $schema->create('archive_blob_2020_01', static function (Blueprint $table): void {
            $table->integer('idarchive');
            $table->string('name');
        });
    }
}
