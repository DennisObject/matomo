<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Tracker\DatabaseVisitRecorder;
use App\Matomo\Tracker\TrackerDeviceProfile;
use App\Matomo\Tracker\TrackerVisitSettings;
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
        $recorder = new DatabaseVisitRecorder($connection);
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
        $this->assertSame(0, $visit['visitor_returning']);
        $this->assertSame(1, $visit['visitor_count_visits']);
        $this->assertSame(0, $visit['visitor_seconds_since_first']);
        $this->assertSame(0, $visit['visitor_seconds_since_last']);
        $this->assertNull($visit['visitor_seconds_since_order']);

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
        $recorder = new DatabaseVisitRecorder($connection);
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
        $firstVisit = $connection->table('log_visit')->orderBy('idvisit')->first();
        $secondVisit = $connection->table('log_visit')->orderByDesc('idvisit')->first();
        $this->assertInstanceOf(stdClass::class, $firstVisit);
        $this->assertInstanceOf(stdClass::class, $secondVisit);
        $this->assertSame(0, $firstVisit->visitor_returning);
        $this->assertSame(1, $firstVisit->visitor_count_visits);
        $this->assertSame(1, $secondVisit->visitor_returning);
        $this->assertSame(2, $secondVisit->visitor_count_visits);
        $this->assertSame(1_801, $secondVisit->visitor_seconds_since_last);
        $this->assertSame(1_801, $secondVisit->visitor_seconds_since_first);
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
            referrerType: 6,
            referrerName: 'newsletter',
            referrerKeyword: 'signup',
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
        $this->assertSame(6, $visit['referer_type']);
        $this->assertSame('newsletter', $visit['referer_name']);
        $this->assertSame('signup', $visit['referer_keyword']);
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

    public function test_stores_page_performance_timings(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            performanceTimings: [
                'time_network' => 12,
                'time_server' => 34,
                'time_transfer' => 56,
                'time_dom_processing' => 78,
                'time_dom_completion' => 90,
                'time_on_load' => 16_777_215,
            ],
        ));

        $action = (array) $connection->table('log_link_visit_action')->first();
        $this->assertSame(12, $action['time_network']);
        $this->assertSame(34, $action['time_server']);
        $this->assertSame(56, $action['time_transfer']);
        $this->assertSame(78, $action['time_dom_processing']);
        $this->assertSame(90, $action['time_dom_completion']);
        $this->assertSame(16_777_215, $action['time_on_load']);
    }

    public function test_stores_site_search_actions_and_visit_totals(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $request = new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/search',
            actionName: 'blue shoes',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            actionType: 8,
            searchCategory: 'products',
            searchCount: 12,
        );

        $recorder->record($request);
        $recorder->record($request);

        $visit = (array) $connection->table('log_visit')->first();
        $this->assertSame(2, $visit['visit_total_actions']);
        $this->assertSame(2, $visit['visit_total_searches']);
        $this->assertNull($visit['visit_entry_idaction_url']);
        $search = (array) $connection->table('log_action')->first();
        $this->assertSame('blue shoes', $search['name']);
        $this->assertSame(8, $search['type']);
        $actions = $connection->table('log_link_visit_action')->get();
        $this->assertCount(2, $actions);
        foreach ($actions as $action) {
            $this->assertNull($action->idaction_url);
            $this->assertSame($search['idaction'], $action->idaction_name);
            $this->assertSame('products', $action->search_cat);
            $this->assertSame(12, $action->search_count);
        }
    }

    public function test_stores_content_impressions_and_interactions(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/content',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            actionType: 13,
            contentName: 'Hero',
            contentPiece: 'Summer sale',
            contentTarget: 'https://shop.example/',
            contentInteraction: 'click',
        ));

        $actions = $connection->table('log_action')->get()->keyBy('name');
        $contentUrl = $actions->get('https://example.test/content');
        $contentName = $actions->get('Hero');
        $contentPiece = $actions->get('Summer sale');
        $contentTarget = $actions->get('https://shop.example/');
        $contentInteraction = $actions->get('click');
        $this->assertInstanceOf(stdClass::class, $contentUrl);
        $this->assertInstanceOf(stdClass::class, $contentName);
        $this->assertInstanceOf(stdClass::class, $contentPiece);
        $this->assertInstanceOf(stdClass::class, $contentTarget);
        $this->assertInstanceOf(stdClass::class, $contentInteraction);
        $this->assertSame(13, $contentUrl->type);
        $this->assertSame(13, $contentName->type);
        $this->assertSame(14, $contentPiece->type);
        $this->assertSame(15, $contentTarget->type);
        $this->assertSame(16, $contentInteraction->type);

        $link = $connection->table('log_link_visit_action')->first();
        $this->assertInstanceOf(stdClass::class, $link);
        $this->assertSame($contentUrl->idaction, $link->idaction_url);
        $this->assertNull($link->idaction_name);
        $this->assertSame($contentName->idaction, $link->idaction_content_name);
        $this->assertSame($contentPiece->idaction, $link->idaction_content_piece);
        $this->assertSame($contentTarget->idaction, $link->idaction_content_target);
        $this->assertSame($contentInteraction->idaction, $link->idaction_content_interaction);
    }

    public function test_heartbeats_only_update_the_active_matching_visit_duration(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $startedAt = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
        CarbonImmutable::setTestNow($startedAt);
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
        ));

        CarbonImmutable::setTestNow($startedAt->addSeconds(45));
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/ignored',
            actionName: 'Ignored',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            heartbeat: true,
        ));
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/ignored',
            actionName: 'Ignored',
            visitorId: 'fedcba9876543210',
            ipAddress: '192.0.2.1',
            userAgent: 'Other browser',
            heartbeat: true,
        ));

        CarbonImmutable::setTestNow($startedAt->addSeconds(1_801));
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/ignored',
            actionName: 'Ignored',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            heartbeat: true,
        ));

        $visit = $connection->table('log_visit')->first();
        $this->assertInstanceOf(stdClass::class, $visit);
        $this->assertSame(1, $visit->visit_total_actions);
        $this->assertSame(45, $visit->visit_total_time);
        $this->assertSame('2026-08-20 12:00:00', $visit->visit_last_action_time);
        $this->assertSame(1, $connection->table('log_visit')->count());
        $this->assertSame(1, $connection->table('log_action')->count());
        $this->assertSame(1, $connection->table('log_link_visit_action')->count());
    }

    public function test_manual_goals_convert_visits_without_recording_actions(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $startedAt = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
        CarbonImmutable::setTestNow($startedAt);
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
        ));

        CarbonImmutable::setTestNow($startedAt->addSeconds(60));
        $goal = new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/goal',
            actionName: 'Ignored',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            goalId: 4,
            goalRevenue: 12.75,
        );
        $recorder->record($goal);
        $recorder->record($goal);

        $visit = $connection->table('log_visit')->first();
        $conversion = $connection->table('log_conversion')->first();
        $this->assertInstanceOf(stdClass::class, $visit);
        $this->assertInstanceOf(stdClass::class, $conversion);
        $this->assertSame(1, $visit->visit_total_actions);
        $this->assertSame(60, $visit->visit_total_time);
        $this->assertSame(1, $visit->visit_goal_converted);
        $this->assertSame('2026-08-20 12:01:00', $visit->visit_last_action_time);
        $this->assertSame(1, $connection->table('log_action')->count());
        $this->assertSame(1, $connection->table('log_link_visit_action')->count());
        $this->assertSame(1, $connection->table('log_conversion')->count());
        $this->assertNull($conversion->idaction_url);
        $this->assertNull($conversion->idlink_va);
        $this->assertSame('https://example.test/goal', $conversion->url);
        $this->assertSame(12.75, $conversion->revenue);
    }

    public function test_manual_goals_create_conversion_only_visits_when_needed(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/goal',
            actionName: 'Ignored',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            goalId: 4,
            goalRevenue: 9.5,
        ));

        $this->assertSame(1, $connection->table('log_visit')->count());
        $this->assertSame(0, $connection->table('log_visit')->value('visit_total_actions'));
        $this->assertSame(1, $connection->table('log_visit')->value('visit_goal_converted'));
        $this->assertSame(0, $connection->table('log_action')->count());
        $this->assertSame(0, $connection->table('log_link_visit_action')->count());
        $this->assertSame(1, $connection->table('log_conversion')->count());
    }

    public function test_ecommerce_orders_convert_visits_without_recording_actions(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $startedAt = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
        CarbonImmutable::setTestNow($startedAt);
        $recorder->record(new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: '',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
        ));

        CarbonImmutable::setTestNow($startedAt->addSeconds(60));
        $order = new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/order',
            actionName: 'Ignored',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            goalRevenue: 42.5,
            ecommerceOrderId: 'order-17',
            ecommerceSubtotal: 35.0,
            ecommerceTax: 2.5,
            ecommerceShipping: 5.0,
            ecommerceDiscount: 1.0,
            ecommerceItems: [[
                'sku' => 'sku-1',
                'name' => 'Shoes',
                'categories' => ['Sale', 'Footwear'],
                'price' => 19.95,
                'quantity' => 2,
            ]],
        );
        $recorder->record($order);
        $recorder->record($order);

        $visit = $connection->table('log_visit')->first();
        $conversion = $connection->table('log_conversion')->first();
        $this->assertInstanceOf(stdClass::class, $visit);
        $this->assertInstanceOf(stdClass::class, $conversion);
        $this->assertSame(1, $visit->visit_total_actions);
        $this->assertSame(1, $visit->visit_goal_converted);
        $this->assertSame(1, $visit->visit_goal_buyer);
        $this->assertSame(5, $connection->table('log_action')->count());
        $this->assertSame(1, $connection->table('log_link_visit_action')->count());
        $this->assertSame(1, $connection->table('log_conversion')->count());
        $this->assertSame(1, $connection->table('log_conversion_item')->count());
        $this->assertNull($conversion->idaction_url);
        $this->assertNull($conversion->idlink_va);
        $this->assertSame(0, $conversion->idgoal);
        $this->assertSame((int) base_convert(substr(md5('order-17'), 0, 8), 16, 10), $conversion->buster);
        $this->assertSame('order-17', $conversion->idorder);
        $this->assertSame(42.5, $conversion->revenue);
        $this->assertSame(35.0, $conversion->revenue_subtotal);
        $this->assertSame(2.5, $conversion->revenue_tax);
        $this->assertSame(5.0, $conversion->revenue_shipping);
        $this->assertSame(1.0, $conversion->revenue_discount);
        $this->assertSame(2, $conversion->items);

        $item = $connection->table('log_conversion_item')->first();
        $this->assertInstanceOf(stdClass::class, $item);
        $this->assertSame('order-17', $item->idorder);
        $this->assertSame(19.95, $item->price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(0, $item->idaction_category3);
        $this->assertSame(0, $item->idaction_category4);
        $this->assertSame(0, $item->idaction_category5);
        $this->assertSame(1, $connection->table('log_action')->where('type', 5)->count());
        $this->assertSame(1, $connection->table('log_action')->where('type', 6)->count());
        $this->assertSame(2, $connection->table('log_action')->where('type', 7)->count());
    }

    public function test_ecommerce_carts_replace_current_items_and_preserve_ordered_status(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $base = [
            'siteId' => 1,
            'url' => 'https://example.test/cart',
            'actionName' => 'Ignored',
            'visitorId' => '0123456789abcdef',
            'ipAddress' => '192.0.2.0',
            'userAgent' => 'Test browser',
        ];
        $recorder->record(new TrackingRequest(...$base, goalRevenue: 42.5, ecommerceOrderId: 'order-17'));
        $recorder->record(new TrackingRequest(
            ...$base,
            goalRevenue: 19.95,
            ecommerceCart: true,
            ecommerceItems: [[
                'sku' => 'sku-1', 'name' => 'Shoes', 'categories' => ['Sale'], 'price' => 19.95, 'quantity' => 1,
            ]],
        ));
        $recorder->record(new TrackingRequest(
            ...$base,
            goalRevenue: 8.5,
            ecommerceCart: true,
            ecommerceItems: [[
                'sku' => 'sku-2', 'name' => 'Hat', 'categories' => ['Sale'], 'price' => 8.5, 'quantity' => 2,
            ]],
        ));

        $cart = $connection->table('log_conversion')->where('idgoal', -1)->first();
        $this->assertInstanceOf(stdClass::class, $cart);
        $this->assertSame(8.5, $cart->revenue);
        $this->assertSame(2, $cart->items);
        $this->assertSame(2, $connection->table('log_conversion')->count());
        $this->assertSame(3, $connection->table('log_visit')->value('visit_goal_buyer'));
        $this->assertSame(0, $connection->table('log_link_visit_action')->count());
        $this->assertSame(2, $connection->table('log_conversion_item')->where('idorder', '0')->count());
        $this->assertSame(1, $connection->table('log_conversion_item')->where('idorder', '0')->where('deleted', 1)->count());
        $this->assertSame(1, $connection->table('log_conversion_item')->where('idorder', '0')->where('deleted', 0)->count());
    }

    public function test_automatic_goals_link_actions_and_apply_repeat_rules(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $request = new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/thanks',
            actionName: 'Thanks',
            visitorId: '0123456789abcdef',
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            automaticGoals: [
                ['id' => 5, 'revenue' => 3.0, 'allowMultiple' => false],
                ['id' => 6, 'revenue' => 4.0, 'allowMultiple' => true],
            ],
        );

        $recorder->record($request);
        $recorder->record($request);

        $this->assertSame(3, $connection->table('log_conversion')->count());
        $this->assertSame(1, $connection->table('log_conversion')->where('idgoal', 5)->count());
        $this->assertSame(2, $connection->table('log_conversion')->where('idgoal', 6)->count());
        $this->assertSame(1, $connection->table('log_visit')->value('visit_goal_converted'));
        $this->assertNotNull($connection->table('log_conversion')->where('idgoal', 5)->value('idaction_url'));
        $this->assertNotNull($connection->table('log_conversion')->where('idgoal', 5)->value('idlink_va'));
    }

    public function test_forces_a_new_visit_when_new_visit_is_requested(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');
        $recorder->record($this->pageView());
        $recorder->record($this->pageView(forceNewVisit: true));

        $this->assertSame(2, $connection->table('log_visit')->count());
        $this->assertSame([0, 1], $connection->table('log_visit')->orderBy('idvisit')->pluck('visitor_returning')->all());
    }

    public function test_attaches_out_of_order_hits_within_the_lookahead_window(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $recorder->record($this->pageView(recordedAt: CarbonImmutable::parse('2026-08-20 12:10:00', 'UTC')));
        $recorder->record($this->pageView(recordedAt: CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC')));

        $this->assertSame(1, $connection->table('log_visit')->count());
        $this->assertSame(2, $connection->table('log_link_visit_action')->count());
    }

    public function test_starts_a_new_visit_when_the_custom_timestamp_is_outside_the_window(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $recorder->record($this->pageView(recordedAt: CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC')));
        $recorder->record($this->pageView(recordedAt: CarbonImmutable::parse('2026-08-20 11:29:59', 'UTC')));

        $this->assertSame(2, $connection->table('log_visit')->count());
    }

    public function test_starts_a_new_visit_after_midnight_in_the_site_timezone(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $recorder->record($this->pageView(
            recordedAt: CarbonImmutable::parse('2026-01-02 04:59:00', 'UTC'),
            timezone: 'America/New_York',
        ));
        $recorder->record($this->pageView(
            recordedAt: CarbonImmutable::parse('2026-01-02 05:01:00', 'UTC'),
            timezone: 'America/New_York',
        ));

        $this->assertSame(2, $connection->table('log_visit')->count());
    }

    public function test_starts_a_new_visit_when_the_user_id_changes(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');
        $recorder->record($this->pageView(userId: 'alice'));
        $recorder->record($this->pageView(userId: 'bob'));

        $this->assertSame(2, $connection->table('log_visit')->count());
    }

    public function test_starts_a_new_visit_when_campaign_attribution_changes(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');
        $recorder->record($this->pageView(referrerType: 6, referrerName: 'spring'));
        $recorder->record($this->pageView(referrerType: 6, referrerName: 'summer'));

        $this->assertSame(2, $connection->table('log_visit')->count());
    }

    public function test_does_not_split_a_direct_visit_when_campaign_data_arrives_later(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');
        $recorder->record($this->pageView());
        $recorder->record($this->pageView(referrerType: 6, referrerName: 'spring'));

        $this->assertSame(1, $connection->table('log_visit')->count());
    }

    public function test_matches_cookieless_visitors_by_config_id(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection);
        $device = new TrackerDeviceProfile(configId: str_repeat("\x01", 8));
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');
        $recorder->record($this->pageView(
            visitorId: 'aaaaaaaaaaaaaaaa',
            hasKnownVisitorId: false,
            device: $device,
        ));
        $recorder->record($this->pageView(
            visitorId: 'bbbbbbbbbbbbbbbb',
            hasKnownVisitorId: false,
            device: $device,
        ));

        $this->assertSame(1, $connection->table('log_visit')->count());
        $this->assertSame(2, $connection->table('log_visit')->value('visit_total_actions'));
        $this->assertSame('aaaaaaaaaaaaaaaa', bin2hex((string) $connection->table('log_visit')->value('idvisitor')));
    }

    public function test_starts_a_new_visit_after_the_configured_action_limit(): void
    {
        $connection = $this->connection();
        $recorder = new DatabaseVisitRecorder($connection, new TrackerVisitSettings(createNewVisitAfterXActions: 1));
        CarbonImmutable::setTestNow('2026-08-20 12:00:00 UTC');
        $recorder->record($this->pageView());
        $recorder->record($this->pageView());

        $this->assertSame(2, $connection->table('log_visit')->count());
    }

    private function pageView(
        string $visitorId = '0123456789abcdef',
        bool $forceNewVisit = false,
        bool $hasKnownVisitorId = true,
        ?string $userId = null,
        int $referrerType = 1,
        string $referrerName = '',
        ?CarbonImmutable $recordedAt = null,
        string $timezone = 'UTC',
        ?TrackerDeviceProfile $device = null,
    ): TrackingRequest {
        return new TrackingRequest(
            siteId: 1,
            url: 'https://example.test/page',
            actionName: '',
            visitorId: $visitorId,
            ipAddress: '192.0.2.0',
            userAgent: 'Test browser',
            userId: $userId,
            referrerType: $referrerType,
            referrerName: $referrerName,
            recordedAt: $recordedAt,
            forceNewVisit: $forceNewVisit,
            hasKnownVisitorId: $hasKnownVisitorId,
            timezone: $timezone,
            device: $device,
        );
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
            $table->unsignedInteger('visit_total_searches');
            $table->unsignedInteger('visit_total_interactions');
            $table->unsignedInteger('visit_total_time');
            $table->unsignedBigInteger('last_idlink_va')->nullable();
            $table->string('user_id', 200)->nullable();
            $table->string('referer_url', 1_500)->nullable();
            $table->unsignedTinyInteger('referer_type')->nullable();
            $table->string('referer_name', 70)->nullable();
            $table->string('referer_keyword', 255)->nullable();
            $table->string('location_browser_lang', 20)->nullable();
            $table->time('visitor_localtime')->nullable();
            $table->string('config_resolution', 18)->nullable();
            $table->boolean('config_cookie')->nullable();
            $table->string('config_browser_name', 10)->nullable();
            $table->string('config_browser_version', 20)->nullable();
            $table->string('config_os', 3)->nullable();
            $table->string('config_os_version', 100)->nullable();
            $table->unsignedTinyInteger('config_device_type')->nullable();
            $table->string('config_device_brand', 100)->nullable();
            $table->string('config_device_model', 100)->nullable();
            $table->boolean('config_flash')->nullable();
            $table->boolean('config_java')->nullable();
            $table->boolean('config_quicktime')->nullable();
            $table->boolean('config_realplayer')->nullable();
            $table->boolean('config_pdf')->nullable();
            $table->boolean('config_windowsmedia')->nullable();
            $table->boolean('config_silverlight')->nullable();
            $table->string('location_country', 3)->nullable();
            $table->string('location_region', 100)->nullable();
            $table->string('location_city', 255)->nullable();
            $table->decimal('location_latitude', 9, 6)->nullable();
            $table->decimal('location_longitude', 9, 6)->nullable();
            $table->string('custom_var_k1', 200)->nullable();
            $table->string('custom_var_v1', 200)->nullable();
            $table->string('custom_dimension_1', 250)->nullable();
            $table->boolean('visit_goal_converted')->default(false);
            $table->boolean('visit_goal_buyer')->default(false);
            $table->unsignedTinyInteger('visitor_returning')->nullable();
            $table->unsignedInteger('visitor_count_visits')->nullable();
            $table->unsignedInteger('visitor_seconds_since_first')->nullable();
            $table->unsignedInteger('visitor_seconds_since_last')->nullable();
            $table->unsignedInteger('visitor_seconds_since_order')->nullable();
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
            $table->unsignedMediumInteger('time_network')->nullable();
            $table->unsignedMediumInteger('time_server')->nullable();
            $table->unsignedMediumInteger('time_transfer')->nullable();
            $table->unsignedMediumInteger('time_dom_processing')->nullable();
            $table->unsignedMediumInteger('time_dom_completion')->nullable();
            $table->unsignedMediumInteger('time_on_load')->nullable();
            $table->string('search_cat', 200)->nullable();
            $table->unsignedInteger('search_count')->nullable();
            $table->unsignedInteger('idaction_content_name')->nullable();
            $table->unsignedInteger('idaction_content_piece')->nullable();
            $table->unsignedInteger('idaction_content_target')->nullable();
            $table->unsignedInteger('idaction_content_interaction')->nullable();
            $table->string('custom_var_k2', 200)->nullable();
            $table->string('custom_var_v2', 200)->nullable();
            $table->string('custom_dimension_2', 250)->nullable();
        });
        $schema->create('log_conversion', static function (Blueprint $table): void {
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
            $table->unsignedSmallInteger('items')->nullable();
            $table->double('revenue_tax')->nullable();
            $table->double('revenue_shipping')->nullable();
            $table->double('revenue_discount')->nullable();
            $table->primary(['idvisit', 'idgoal', 'buster']);
            $table->unique(['idsite', 'idorder']);
        });
        $schema->create('log_conversion_item', static function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->binary('idvisitor');
            $table->dateTime('server_time');
            $table->unsignedBigInteger('idvisit');
            $table->string('idorder');
            $table->unsignedInteger('idaction_sku');
            $table->unsignedInteger('idaction_name');
            $table->unsignedInteger('idaction_category');
            $table->unsignedInteger('idaction_category2');
            $table->unsignedInteger('idaction_category3');
            $table->unsignedInteger('idaction_category4');
            $table->unsignedInteger('idaction_category5');
            $table->double('price');
            $table->unsignedInteger('quantity');
            $table->boolean('deleted');
            $table->primary(['idvisit', 'idorder', 'idaction_sku']);
        });

        return $connection;
    }
}
