<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Tracker\DatabaseVisitRecorder;
use App\Matomo\Tracker\TrackingRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseVisitRecorderTest extends TestCase
{
    public function test_creates_and_updates_a_visit_with_actions(): void
    {
        config()->set('database.connections.tracker_test', ['driver' => 'sqlite', 'database' => ':memory:']);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('tracker_test');

        $connection = $databases->connection('tracker_test');
        $schema = $connection->getSchemaBuilder();
        $schema->create('log_visit', function (Blueprint $table): void {
            $table->id('idvisit');
            $table->unsignedInteger('idsite');
            $table->binary('idvisitor');
            $table->dateTime('visit_last_action_time');
            $table->binary('config_id');
            $table->binary('location_ip');
            $table->dateTime('visit_first_action_time');
            $table->unsignedInteger('visit_entry_idaction_url');
            $table->unsignedInteger('visit_exit_idaction_url');
            $table->unsignedSmallInteger('visit_total_actions');
            $table->unsignedSmallInteger('visit_total_events');
        });
        $schema->create('log_action', function (Blueprint $table): void {
            $table->id('idaction');
            $table->text('name');
            $table->unsignedInteger('hash');
            $table->unsignedTinyInteger('type');
            $table->unsignedTinyInteger('url_prefix')->nullable();
        });
        $schema->create('log_link_visit_action', function (Blueprint $table): void {
            $table->id('idlink_va');
            $table->unsignedInteger('idsite');
            $table->binary('idvisitor');
            $table->unsignedInteger('idvisit');
            $table->unsignedInteger('idaction_url');
            $table->unsignedInteger('idaction_name')->nullable();
            $table->dateTime('server_time');
            $table->unsignedInteger('idaction_url_ref');
            $table->unsignedInteger('time_spent_ref_action');
        });
        $recorder = new DatabaseVisitRecorder($connection);
        $request = new TrackingRequest(1, 'https://example.test/', '', '0123456789abcdef', '127.0.0.1', 'test', 1, null, null, null, null);

        $recorder->record($request);
        $recorder->record($request);

        $this->assertSame(1, $connection->table('log_visit')->count());
        $this->assertSame(2, $connection->table('log_link_visit_action')->count());
        $this->assertSame(2, $connection->table('log_visit')->value('visit_total_actions'));
    }
}
