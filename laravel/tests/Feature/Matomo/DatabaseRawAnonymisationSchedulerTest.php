<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Privacy\DatabaseRawAnonymisationScheduler;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseRawAnonymisationSchedulerTest extends TestCase
{
    public function test_inserts_the_legacy_job_shape(): void
    {
        config()->set('database.connections.matomo_raw_anonymisation_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_raw_anonymisation_test');

        $connection = $databases->connection('matomo_raw_anonymisation_test');
        $connection->getSchemaBuilder()->create('privacy_logdata_anonymizations', function (Blueprint $table): void {
            $table->increments('idlogdata_anonymization');
            $table->text('idsites')->nullable();
            $table->dateTime('date_start');
            $table->dateTime('date_end');
            $table->boolean('anonymize_ip');
            $table->boolean('anonymize_location');
            $table->boolean('anonymize_userid');
            $table->text('unset_visit_columns');
            $table->text('unset_link_visit_action_columns');
            $table->dateTime('scheduled_date');
            $table->dateTime('job_start_date')->nullable();
            $table->string('requester');
        });
        $scheduler = new DatabaseRawAnonymisationScheduler($connection);

        $id = $scheduler->schedule(
            'admin',
            [7],
            '2026-08-01 00:00:00',
            '2026-08-02 23:59:59',
            true,
            false,
            false,
            ['config_device_type'],
            [],
        );

        $row = $connection->table('privacy_logdata_anonymizations')->first();
        $this->assertSame(1, $id);
        $this->assertNotNull($row);
        $this->assertSame('[7]', $row->idsites);
        $this->assertSame('["config_device_type"]', $row->unset_visit_columns);
        $this->assertSame('[]', $row->unset_link_visit_action_columns);
        $this->assertSame('admin', $row->requester);
        $this->assertNull($row->job_start_date);
    }
}
