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
            $table->boolean('visit_goal_converted')->default(false);
            $table->boolean('visit_goal_buyer')->default(false);
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
        $schema->create('log_conversion', function (Blueprint $table): void {
            $table->unsignedBigInteger('idvisit');
            $table->unsignedInteger('idsite');
            $table->binary('idvisitor');
            $table->dateTime('server_time');
            $table->unsignedInteger('idaction_url')->nullable();
            $table->unsignedBigInteger('idlink_va')->nullable();
            $table->integer('idgoal');
            $table->unsignedInteger('buster');
            $table->string('idorder')->nullable();
            $table->text('url');
            $table->double('revenue')->nullable();
            $table->double('revenue_subtotal')->nullable();
            $table->primary(['idvisit', 'idgoal', 'buster']);
        });
        $recorder = new DatabaseVisitRecorder($connection);
        $request = $this->request();

        $recorder->record($request);
        $recorder->record($request);

        $heartbeat = $this->request(true);
        $recorder->record($heartbeat);
        $recorder->record($this->request(goalId: 4));
        $recorder->record($this->request(orderId: 'order-17'));

        $this->assertSame(1, $connection->table('log_visit')->count());
        $this->assertSame(4, $connection->table('log_link_visit_action')->count());
        $this->assertSame(4, $connection->table('log_visit')->value('visit_total_actions'));
        $this->assertSame(2, $connection->table('log_conversion')->count());
        $this->assertSame(9.5, $connection->table('log_conversion')->where('idgoal', 4)->value('revenue'));
        $this->assertSame('order-17', $connection->table('log_conversion')->where('idgoal', 0)->value('idorder'));
        $this->assertSame(1, $connection->table('log_visit')->value('visit_goal_converted'));
        $this->assertSame(1, $connection->table('log_visit')->value('visit_goal_buyer'));
    }

    private function request(bool $heartbeat = false, ?int $goalId = null, ?string $orderId = null): TrackingRequest
    {
        return new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '127.0.0.1',
            userAgent: 'test',
            actionType: 1,
            eventCategory: null,
            eventAction: null,
            eventName: null,
            eventValue: null,
            searchCategory: null,
            searchCount: null,
            contentName: null,
            contentPiece: null,
            contentTarget: null,
            contentInteraction: null,
            goalId: $goalId,
            goalRevenue: $goalId === null && $orderId === null ? null : ($goalId === null ? 42.5 : 9.5),
            goalAllowsMultiple: false,
            ecommerceOrderId: $orderId,
            ecommerceSubtotal: $orderId === null ? null : 35.0,
            ecommerceTax: null,
            ecommerceShipping: null,
            ecommerceDiscount: null,
            userId: null,
            referrerUrl: '',
            referrerType: 1,
            referrerName: '',
            referrerKeyword: '',
            browserLanguage: '',
            localTime: '00:00:00',
            resolution: '',
            cookiesEnabled: false,
            heartbeat: $heartbeat,
            visitProperties: [],
            actionProperties: [],
            performanceTimings: [],
        );
    }
}
