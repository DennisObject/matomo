<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Tracker\DatabaseVisitRecorder;
use App\Matomo\Tracker\TrackingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use stdClass;
use Tests\TestCase;

final class DatabaseVisitRecorderTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_creates_and_updates_a_complete_page_view_visit(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection, 1_800);
        $request = new TrackingRequest(
            1,
            'https://www.example.test/page',
            'Example title',
            '0123456789abcdef',
            '192.0.2.0',
            'Test browser',
        );
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');

        $recorder->record($request);
        CarbonImmutable::setTestNow('2026-08-20 12:00:10 UTC');
        $recorder->record($request);

        $this->assertSame(1, $connection->table('log_visit')->count());
        $this->assertSame(2, $connection->table('log_link_visit_action')->count());
        $this->assertSame(2, $connection->table('log_action')->count());

        $visit = (array) $connection->table('log_visit')->first();
        $this->assertSame('2026-08-20 12:00:00', $visit['visit_first_action_time']);
        $this->assertSame('2026-08-20 12:00:10', $visit['visit_last_action_time']);
        $this->assertSame(2, $visit['visit_total_actions']);
        $this->assertSame(0, $visit['visit_total_events']);
        $this->assertSame(2, $visit['visit_total_interactions']);
        $this->assertSame(10, $visit['visit_total_time']);
        $this->assertSame(2, $visit['last_idlink_va']);
        $this->assertSame('192.0.2.0', inet_ntop($visit['location_ip']));

        $url = (array) $connection->table('log_action')->where('type', 1)->first();
        $this->assertSame('example.test/page', $url['name']);
        $this->assertSame(3, $url['url_prefix']);
        $this->assertSame((int) sprintf('%u', crc32('example.test/page')), $url['hash']);

        $links = $connection->table('log_link_visit_action')->orderBy('idlink_va')->get();
        $firstLink = $links->first();
        $secondLink = $links->last();
        $this->assertInstanceOf(stdClass::class, $firstLink);
        $this->assertInstanceOf(stdClass::class, $secondLink);
        $this->assertSame(1, $firstLink->pageview_position);
        $this->assertSame(0, $firstLink->idaction_url_ref);
        $this->assertSame(2, $secondLink->pageview_position);
        $this->assertSame($url['idaction'], $secondLink->idaction_url_ref);
        $this->assertSame(10, $secondLink->time_spent_ref_action);
    }

    public function test_starts_a_new_visit_after_the_configured_timeout(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection, 1_800);
        $request = new TrackingRequest(
            1,
            'https://example.test/',
            '',
            '0123456789abcdef',
            '192.0.2.0',
            'Test browser',
        );
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');
        $recorder->record($request);
        CarbonImmutable::setTestNow('2026-08-20 12:30:01 UTC');

        $recorder->record($request);

        $this->assertSame(2, $connection->table('log_visit')->count());
        $this->assertSame([1, 1], $connection->table('log_visit')->orderBy('idvisit')->pluck('visit_total_actions')->all());
    }

    public function test_records_event_dimensions_and_value(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $request = new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: 'Ignored for events',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            actionType: 10,
            eventCategory: 'Video',
            eventAction: 'Play',
            eventName: 'Trailer',
            eventValue: 2.5,
        );

        $recorder->record($request);
        $recorder->record($request);

        $actions = $connection->table('log_action')->get()->keyBy('name');
        $eventUrl = $actions->get('example.test/page');
        $category = $actions->get('Video');
        $action = $actions->get('Play');
        $name = $actions->get('Trailer');
        $this->assertInstanceOf(stdClass::class, $eventUrl);
        $this->assertInstanceOf(stdClass::class, $category);
        $this->assertInstanceOf(stdClass::class, $action);
        $this->assertInstanceOf(stdClass::class, $name);
        $this->assertSame(10, $eventUrl->type);
        $this->assertSame(2, $eventUrl->url_prefix);
        $this->assertSame(10, $category->type);
        $this->assertSame(11, $action->type);
        $this->assertSame(12, $name->type);

        $link = $connection->table('log_link_visit_action')->first();
        $this->assertInstanceOf(stdClass::class, $link);
        $this->assertSame($eventUrl->idaction, $link->idaction_url);
        $this->assertNull($link->idaction_name);
        $this->assertSame($category->idaction, $link->idaction_event_category);
        $this->assertSame($action->idaction, $link->idaction_event_action);
        $this->assertSame($name->idaction, $link->idaction_event_name);
        $this->assertSame(2.5, $link->custom_float);
        $this->assertSame(2, $connection->table('log_visit')->value('visit_total_events'));
    }

    public function test_stores_new_visit_context_without_clearing_the_user_id(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $request = new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            userId: 'alice',
            referrerUrl: 'https://search.example/',
            browserLanguage: 'en-us',
            localTime: '14:05:09',
            resolution: '1920x1080',
            cookiesEnabled: true,
        );

        $recorder->record($request);
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/next',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
        ));

        $visit = (array) $connection->table('log_visit')->first();
        $this->assertSame('alice', $visit['user_id']);
        $this->assertSame('https://search.example/', $visit['referer_url']);
        $this->assertSame('en-us', $visit['location_browser_lang']);
        $this->assertSame('14:05:09', $visit['visitor_localtime']);
        $this->assertSame('1920x1080', $visit['config_resolution']);
        $this->assertSame(1, $visit['config_cookie']);
    }

    public function test_stores_visit_and_action_custom_properties(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $request = new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            visitProperties: [
                'custom_var_k1' => 'Plan',
                'custom_var_v1' => 'Pro',
                'custom_dimension_1' => 'Account',
            ],
            actionProperties: [
                'custom_var_k2' => 'Author',
                'custom_var_v2' => 'Ada',
                'custom_dimension_2' => 'Article',
            ],
        );

        $recorder->record($request);

        $visit = (array) $connection->table('log_visit')->first();
        $this->assertSame('Plan', $visit['custom_var_k1']);
        $this->assertSame('Pro', $visit['custom_var_v1']);
        $this->assertSame('Account', $visit['custom_dimension_1']);
        $action = (array) $connection->table('log_link_visit_action')->first();
        $this->assertSame('Author', $action['custom_var_k2']);
        $this->assertSame('Ada', $action['custom_var_v2']);
        $this->assertSame('Article', $action['custom_dimension_2']);
    }

    private function connection(): ConnectionInterface
    {
        config()->set('database.connections.tracker_test', ['driver' => 'sqlite', 'database' => ':memory:']);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('tracker_test');

        $connection = $databases->connection('tracker_test');
        $schema = $connection->getSchemaBuilder();
        $schema->create('log_visit', static function (Blueprint $table): void {
            $table->id('idvisit');
            $table->unsignedInteger('idsite');
            $table->binary('idvisitor');
            $table->dateTime('visit_last_action_time');
            $table->binary('config_id');
            $table->binary('location_ip');
            $table->dateTime('visit_first_action_time');
            $table->unsignedInteger('visit_entry_idaction_url')->nullable();
            $table->unsignedInteger('visit_entry_idaction_name')->nullable();
            $table->unsignedInteger('visit_exit_idaction_url')->nullable();
            $table->unsignedInteger('visit_exit_idaction_name')->nullable();
            $table->unsignedInteger('visit_total_actions');
            $table->unsignedInteger('visit_total_events');
            $table->unsignedInteger('visit_total_interactions');
            $table->unsignedInteger('visit_total_time');
            $table->unsignedBigInteger('last_idlink_va')->nullable();
            $table->string('user_id', 200)->nullable();
            $table->string('referer_url', 1_500)->nullable();
            $table->string('location_browser_lang', 20)->nullable();
            $table->time('visitor_localtime')->nullable();
            $table->string('config_resolution', 18)->nullable();
            $table->boolean('config_cookie')->nullable();
            $table->string('custom_var_k1', 200)->nullable();
            $table->string('custom_var_v1', 200)->nullable();
            $table->string('custom_dimension_1', 250)->nullable();
        });
        $schema->create('log_action', static function (Blueprint $table): void {
            $table->id('idaction');
            $table->text('name')->nullable();
            $table->unsignedInteger('hash');
            $table->unsignedTinyInteger('type')->nullable();
            $table->unsignedTinyInteger('url_prefix')->nullable();
        });
        $schema->create('log_link_visit_action', static function (Blueprint $table): void {
            $table->id('idlink_va');
            $table->unsignedInteger('idsite');
            $table->binary('idvisitor');
            $table->unsignedBigInteger('idvisit');
            $table->unsignedInteger('idaction_url')->nullable();
            $table->unsignedInteger('idaction_name')->nullable();
            $table->unsignedInteger('idaction_url_ref')->default(0);
            $table->unsignedInteger('idaction_name_ref')->nullable();
            $table->unsignedInteger('idaction_event_category')->nullable();
            $table->unsignedInteger('idaction_event_action')->nullable();
            $table->unsignedInteger('idaction_event_name')->nullable();
            $table->float('custom_float')->nullable();
            $table->dateTime('server_time');
            $table->unsignedInteger('pageview_position')->nullable();
            $table->unsignedInteger('time_spent_ref_action')->nullable();
            $table->string('custom_var_k2', 200)->nullable();
            $table->string('custom_var_v2', 200)->nullable();
            $table->string('custom_dimension_2', 250)->nullable();
        });

        return $connection;
    }
}
