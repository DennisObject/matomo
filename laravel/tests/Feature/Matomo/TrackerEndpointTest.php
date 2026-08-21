<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Config\InstallationConfig;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Geolocation\GeolocationProviderRegistry;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Settings\PolicySettingRepository;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Tracker\MatomoCookie;
use App\Matomo\Tracker\ReferrerSpamList;
use App\Matomo\Tracker\TrackingRequest;
use App\Matomo\Tracker\TrackingRequestPolicy;
use App\Matomo\Tracker\VisitRecorder;
use Tests\TestCase;

final class TrackerEndpointTest extends TestCase
{
    private string $configurationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam('/dev/shm', 'matomo-tracker-config-');
        $this->assertIsString($path);
        $this->configurationPath = $path;
        $this->assertNotFalse(file_put_contents($path, <<<'INI'
            [database]
            host = "database"
            username = "matomo"
            password = "password"
            dbname = "matomo"
            tables_prefix = "matomo_"
            adapter = "PDO\\MYSQL"

            [General]
            salt = "test-salt"

            [Tracker]
            INI));
        $this->app->make('config')->set('matomo.config_path', $path);
        $this->app->instance(PolicySettingRepository::class, $this->createStub(PolicySettingRepository::class));
    }

    protected function tearDown(): void
    {
        unlink($this->configurationPath);

        parent::tearDown();
    }

    public function test_records_valid_page_view_and_returns_uncached_pixel(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->siteId === 1
                && $request->visitorId === '0123456789abcdef'
                && $request->ipAddress === '127.0.0.0',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif')
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertHeaderContains('Cache-Control', 'no-cache')
            ->assertHeaderContains('Cache-Control', 'must-revalidate');
    }

    public function test_requires_the_record_flag(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get('/matomo.php')->assertOk();
    }

    public function test_accepts_post_requests_on_both_tracker_entrypoints(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', $this->parameters())->assertOk();
        $this->post('/piwik.php', $this->parameters())->assertOk();
    }

    public function test_rejects_invalid_tracking_input(): void
    {
        $this->bindSite();
        $this->get('/matomo.php?rec=1&idsite=0&url=javascript%3Aalert%281%29')->assertBadRequest();
    }

    public function test_records_event_parameters(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 10
                && $request->eventCategory === 'Video'
                && $request->eventAction === 'Play'
                && $request->eventName === 'Trailer'
                && $request->eventValue === 2.5,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'e_c' => 'Video',
            'e_a' => 'Play',
            'e_n' => 'Trailer',
            'e_v' => '2.5',
        ]))->assertOk();
    }

    public function test_validates_and_records_visitor_context(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->userId === 'alice'
                && $request->referrerUrl === 'https://search.example/'
                && $request->browserLanguage === 'en-us'
                && $request->localTime === '14:05:09'
                && $request->resolution === '1920x1080'
                && $request->cookiesEnabled,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get($this->url([
            'uid' => 'alice',
            'urlref' => 'https://search.example/',
            'lang' => 'en-US',
            'h' => '14',
            'm' => '5',
            's' => '9',
            'res' => '1920x1080',
            'cookie' => '1',
        ]))->assertOk();
    }

    public function test_rejects_invalid_visitor_context(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['urlref' => 'javascript:alert(1)']))->assertBadRequest();
        $this->get($this->url(['h' => '24']))->assertBadRequest();
        $this->get($this->url(['res' => '1920 by 1080']))->assertBadRequest();
        $this->get($this->url(['res' => '1920x1080garbage']))->assertBadRequest();
    }

    public function test_applies_privacy_settings_to_visitor_context(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('PrivacyManager.anonymizeReferrer', 'exclude_query');
        $settings = $this->createStub(PolicySettingRepository::class);
        $settings->method('siteBoolean')->willReturn(true);
        $this->app->instance(PolicySettingRepository::class, $settings);
        $compliance = $this->createStub(CompliancePolicyStateRepository::class);
        $compliance->method('settingEnforced')->willReturnCallback(
            static fn (string $plugin, string $setting, ?int $siteId): bool => $siteId === 1
                && in_array($plugin.'.'.$setting, [
                    'PrivacyManager.ReferrerAnonymisation',
                    'Resolution.ScreenResolutionDetectionDisabled',
                ], true),
        );
        $this->app->instance(CompliancePolicyStateRepository::class, $compliance);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->userId === null
                && $request->referrerUrl === 'https://search.example/'
                && $request->resolution === 'unknown',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'uid' => 'alice',
            'urlref' => 'https://search.example/private?q=secret',
            'res' => '1920x1080',
        ]))->assertOk();
    }

    public function test_records_bulk_tracking_requests(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fa',
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fb',
        ]])->assertOk()->assertHeader('Content-Type', 'image/gif');
    }

    public function test_records_scoped_custom_dimensions_and_variables(): void
    {
        $this->bindSite();
        $this->bindDimensions([
            ['idcustomdimension' => 7, 'index' => 1, 'scope' => 'visit', 'active' => true],
            ['idcustomdimension' => 8, 'index' => 2, 'scope' => 'action', 'active' => true],
        ]);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitProperties === [
                'custom_var_k1' => 'Plan', 'custom_var_v1' => 'Pro', 'custom_dimension_1' => 'Account',
            ] && $request->actionProperties === [
                'custom_var_k2' => 'Author', 'custom_var_v2' => 'Ada', 'custom_dimension_2' => 'Article',
            ],
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            '_cvar' => '{"1":["Plan","Pro"]}',
            'cvar' => '{"2":["Author","Ada"]}',
            'dimension7' => 'Account',
            'dimension8' => 'Article',
        ]))->assertOk();
    }

    public function test_rejects_invalid_custom_data(): void
    {
        $this->bindSite();
        $this->bindDimensions([
            ['idcustomdimension' => 7, 'index' => 1, 'scope' => 'visit', 'active' => true],
        ]);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['_cvar' => '{invalid']))->assertBadRequest();
        $this->get($this->url(['_cvar' => 'null']))->assertBadRequest();
        $this->get($this->url(['cvar' => '{"6":["Name","Value"]}']))->assertBadRequest();
        $this->get($this->url().'&dimension7%5B0%5D=nested')->assertBadRequest();
    }

    public function test_caches_custom_dimensions_for_bulk_requests(): void
    {
        $this->bindSite();
        $dimensions = $this->createMock(CustomDimensionRepository::class);
        $dimensions->expects($this->once())->method('configuredForSite')->with(1)->willReturn([
            ['idcustomdimension' => 7, 'index' => 1, 'scope' => 'visit', 'active' => true],
        ]);
        $this->app->instance(CustomDimensionRepository::class, $dimensions);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fa&dimension7=One',
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fb&dimension7=Two',
        ]])->assertOk();
    }

    public function test_attributes_campaigns_and_referrers(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->referrerType === 6
                ? $request->referrerName === 'summer' && $request->referrerKeyword === 'shoes'
                : $request->referrerType === 3 && $request->referrerName === 'news.example',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'url' => 'https://example.test/?utm_campaign=summer&utm_term=shoes',
        ]))->assertOk();
        $this->get($this->url([
            'urlref' => 'https://news.example/story',
        ]))->assertOk();
    }

    public function test_classifies_search_social_and_ai_referrers(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(4))->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => match ($request->actionName) {
                'search' => $request->referrerType === 2
                    && $request->referrerName === 'Google'
                    && $request->referrerKeyword === 'blue shoes',
                'social' => $request->referrerType === 7 && $request->referrerName === 'Facebook',
                'ai' => $request->referrerType === 8 && str_contains(strtolower($request->referrerName), 'chatgpt'),
                'ai-source' => $request->referrerType === 8,
                default => false,
            },
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'action_name' => 'search',
            'urlref' => 'https://www.google.com/search?q=blue+shoes',
        ]))->assertOk();
        $this->get($this->url([
            'action_name' => 'social',
            'urlref' => 'https://www.facebook.com/post/1',
        ]))->assertOk();
        $this->get($this->url([
            'action_name' => 'ai',
            'urlref' => 'https://chatgpt.com/',
            'utm_campaign' => 'should-not-win',
        ]))->assertOk();
        $this->get($this->url([
            'action_name' => 'ai-source',
            'utm_source' => 'chatgpt.com',
        ]))->assertOk();
    }

    public function test_treats_excluded_referrers_as_direct_entry(): void
    {
        $this->bindSite(['excluded_referrers' => 'ads.example']);
        $this->mutableOptions()->set('SitesManager_ExcludedReferrersGlobal', 'spam.example');
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->referrerType === 1
                && $request->referrerUrl === '',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['urlref' => 'https://ads.example/click']))->assertOk();
        $this->get($this->url(['urlref' => 'https://spam.example/x']))->assertOk();
    }

    public function test_attributes_configured_tracker_parameters_and_ignores_internal_referrers(): void
    {
        $this->bindSite(urls: ['https://example.test', 'https://alias.test']);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(3))->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => match ($request->actionName) {
                'campaign' => $request->referrerType === 6
                    && $request->referrerName === 'newsletter'
                    && $request->referrerKeyword === 'signup',
                'internal' => $request->referrerType === 1 && $request->referrerUrl === 'https://alias.test/start',
                'ignored' => $request->referrerType === 1 && $request->referrerUrl === '',
                default => false,
            },
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'action_name' => 'campaign',
            'utm_campaign' => 'Newsletter',
            'utm_term' => 'Signup',
        ]))->assertOk();
        $this->get($this->url([
            'action_name' => 'internal',
            'urlref' => 'https://alias.test/start',
        ]))->assertOk();
        $this->get($this->url([
            'action_name' => 'ignored',
            'url' => 'https://example.test/page?ignore_referrer=1',
            'urlref' => 'https://news.example/story',
        ]))->assertOk();
    }

    public function test_masks_campaign_values_and_anonymises_website_names(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('PrivacyManager.anonymizeReferrer', 'exclude_all');
        $settings = $this->createStub(PolicySettingRepository::class);
        $settings->method('siteBoolean')->willReturnCallback(
            static fn (int $siteId, string $plugin, string $setting): ?bool => $siteId === 1
                && $plugin === 'PrivacyManager'
                && $setting === 'campaign_parameter_values_masked'
                    ? true
                    : null,
        );
        $this->app->instance(PolicySettingRepository::class, $settings);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->referrerType === 6
                ? $request->referrerName === '__discarded_by_policy__'
                    && $request->referrerKeyword === '__discarded_by_policy__'
                : $request->referrerType === 3
                    && $request->referrerName === ''
                    && $request->referrerUrl === '',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['utm_campaign' => 'Private', 'utm_term' => 'Secret']))->assertOk();
        $this->get($this->url(['urlref' => 'https://news.example/private']))->assertOk();
    }

    public function test_records_page_performance_timings(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->performanceTimings === [
                'time_network' => 12,
                'time_server' => 34,
                'time_transfer' => 56,
                'time_dom_processing' => 78,
                'time_dom_completion' => 90,
                'time_on_load' => 16_777_215,
            ],
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'pf_net' => '12',
            'pf_srv' => '34',
            'pf_tfr' => '56',
            'pf_dm1' => '78',
            'pf_dm2' => '90',
            'pf_onl' => '16777215',
        ]))->assertOk();
    }

    public function test_records_site_search_parameters(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 8
                && $request->actionName === 'blue shoes'
                && $request->searchCategory === 'products'
                && $request->searchCount === 12,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'url' => 'https://example.test/search',
            'search' => 'blue shoes',
            'search_cat' => 'products',
            'search_count' => '12',
        ]))->assertOk();
    }

    public function test_records_content_impressions_and_interactions(): void
    {
        $this->bindSite(['excluded_parameters' => 'secret']);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 13
                && $request->url === 'https://example.test/content?keep=yes'
                && $request->contentName === 'Hero'
                && $request->contentPiece === 'Summer sale'
                && $request->contentTarget === 'https://shop.example/'
                && $request->contentInteraction === 'click'
                && $request->searchCategory === null
                && $request->searchCount === null,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'url' => 'https://example.test/content?secret=hidden&keep=yes',
            'c_n' => 'Hero',
            'c_p' => 'Summer sale',
            'c_t' => 'https://shop.example/',
            'c_i' => 'click',
            'search' => 'ignored',
            'search_cat' => 'ignored',
            'search_count' => '12',
        ]))->assertOk();
    }

    public function test_marks_heartbeat_requests(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->heartbeat,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['ping' => '1']))->assertOk();
    }

    public function test_validates_and_records_manual_goals(): void
    {
        $this->bindSite();
        $goals = $this->createStub(GoalRepository::class);
        $goals->method('findActive')->willReturn(['idgoal' => 4, 'revenue' => 9.5, 'allow_multiple' => 0]);
        $this->app->instance(GoalRepository::class, $goals);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorded = [];
        $recorder->expects($this->exactly(2))->method('record')->willReturnCallback(
            static function (TrackingRequest $request) use (&$recorded): void {
                $recorded[] = $request;
            },
        );
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['idgoal' => '4', 'revenue' => '12.755']))->assertOk();
        $this->get($this->url(['idgoal' => '4']))->assertOk();

        $this->assertSame(12.76, $recorded[0]->goalRevenue);
        $this->assertSame(9.5, $recorded[1]->goalRevenue);
        $this->assertSame(4, $recorded[1]->goalId);
        $this->assertFalse($recorded[1]->goalAllowsMultiple);
    }

    public function test_rejects_invalid_manual_goals(): void
    {
        $this->bindSite();
        $goals = $this->createStub(GoalRepository::class);
        $goals->method('findActive')->willReturnCallback(
            static fn (int $siteId, int $goalId): ?array => $goalId === 4
                ? ['idgoal' => 4, 'revenue' => 9.5, 'allow_multiple' => 0]
                : null,
        );
        $this->app->instance(GoalRepository::class, $goals);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['idgoal' => '0']))->assertBadRequest();
        $this->get($this->url(['idgoal' => '5']))->assertBadRequest();
        $this->get($this->url(['idgoal' => '4', 'revenue' => 'not-a-number']))->assertBadRequest();
    }

    public function test_rejects_blank_content_names(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['c_n' => '   ']))->assertBadRequest();
    }

    public function test_ignores_invalid_search_counts_and_disabled_site_search(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 8
                && $request->searchCount === null,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get($this->url(['search' => 'blue shoes', 'search_count' => '-2']))->assertOk();

        $this->bindSite(['sitesearch' => 0]);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 1
                && $request->searchCategory === null
                && $request->searchCount === null,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $this->get($this->url(['search' => 'ignored', 'search_count' => '12']))->assertOk();
    }

    public function test_rejects_negative_page_performance_timings(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['pf_net' => '-2']))->assertBadRequest();
    }

    public function test_ignores_unsupported_page_performance_timings(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->performanceTimings === [],
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'pf_net' => 'not-an-integer',
            'pf_srv' => '-1',
            'pf_tfr' => '16777216',
        ]))->assertOk();
    }

    public function test_validates_and_records_ecommerce_orders(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->ecommerceOrderId === 'order-17'
                && $request->goalRevenue === 42.56
                && $request->ecommerceSubtotal === 35.12
                && $request->ecommerceTax === 2.56
                && $request->ecommerceShipping === 5.0
                && $request->ecommerceDiscount === 1.25,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'idgoal' => '0',
            'ec_id' => 'order-17',
            'revenue' => '42.555',
            'ec_st' => '35.123',
            'ec_tx' => '2.555',
            'ec_sh' => '5',
            'ec_dt' => '1.25',
        ]))->assertOk();
    }

    public function test_validates_ecommerce_items(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->ecommerceItems === [[
                'sku' => 'sku-1', 'name' => 'Shoes', 'categories' => ['Sale', 'Footwear'],
                'price' => 19.95, 'quantity' => 2,
            ]],
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $items = [['sku-1', 'Shoes', ['Sale', 'Footwear'], 19.951, 2]];

        $this->get($this->url([
            'idgoal' => '0',
            'ec_id' => 'order-1',
            'revenue' => '39.9',
            'ec_items' => json_encode($items, JSON_THROW_ON_ERROR),
        ]))->assertOk();
    }

    public function test_records_ecommerce_cart_updates_without_an_order_id(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->ecommerceCart
                && $request->ecommerceOrderId === null
                && $request->goalRevenue === 19.95,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);
        $items = [['sku-1', 'Shoes', 'Sale', 19.95, 1]];

        $this->get($this->url([
            'idgoal' => '0',
            'revenue' => '19.95',
            'ec_items' => json_encode($items, JSON_THROW_ON_ERROR),
        ]))->assertOk();
    }

    public function test_matches_automatic_url_and_event_goals(): void
    {
        $this->bindSite();
        $goals = $this->createStub(GoalRepository::class);
        $goals->method('activeForSites')->willReturn([
            ['idgoal' => 2, 'match_attribute' => 'url', 'pattern_type' => 'contains', 'pattern' => '/thanks', 'revenue' => 5, 'allow_multiple' => 0],
            ['idgoal' => 3, 'match_attribute' => 'event_action', 'pattern_type' => 'exact', 'pattern' => 'Buy', 'revenue' => 0, 'event_value_as_revenue' => 1, 'allow_multiple' => 1],
        ]);
        $this->app->instance(GoalRepository::class, $goals);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->exactly(2))->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->automaticGoals === ($request->actionType === 1
                ? [['id' => 2, 'revenue' => 5.0, 'allowMultiple' => false]]
                : [['id' => 3, 'revenue' => 17.0, 'allowMultiple' => true]]),
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['url' => 'https://example.test/thanks']))->assertOk();
        $this->get($this->url(['e_c' => 'Shop', 'e_a' => 'Buy', 'e_v' => '17']))->assertOk();
    }

    public function test_rejects_invalid_ecommerce_orders(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['idgoal' => '4', 'ec_id' => 'order-17']))->assertBadRequest();
        $this->get($this->url(['idgoal' => '0', 'ec_id' => 'order-17', 'revenue' => 'invalid']))
            ->assertBadRequest();
        $this->get($this->url(['idgoal' => '0', 'ec_id' => 'order-17', 'ec_tx' => 'INF']))
            ->assertBadRequest();
        $this->get($this->url(['idgoal' => '0', 'ec_id' => 'order-17', 'ec_items' => '{']))
            ->assertBadRequest();
        $this->get($this->url(['ec_items' => '[['.json_encode('sku-1').']]']))
            ->assertBadRequest();
        $this->get($this->url([
            'idgoal' => '0',
            'ec_id' => 'order-17',
            'ec_items' => json_encode([['sku-1', 'Shoes', [], 1, 65_536]], JSON_THROW_ON_ERROR),
        ]))->assertBadRequest();
        $this->get($this->url([
            'idgoal' => '0',
            'ec_id' => 'order-17',
            'ec_items' => json_encode([['sku-1'], ['sku-1']], JSON_THROW_ON_ERROR),
        ]))->assertBadRequest();
    }

    public function test_anonymizes_ecommerce_order_ids_when_configured(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('PrivacyManager.anonymizeOrderId', '1');
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->goalRevenue === 0.0
                && $request->ecommerceOrderId !== 'order-17'
                && preg_match('/^[a-f0-9]{40}$/D', (string) $request->ecommerceOrderId) === 1,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['idgoal' => '0', 'ec_id' => 'order-17']))->assertOk();
    }

    public function test_ignores_page_performance_timings_for_non_pageviews(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 10
                && $request->performanceTimings === [],
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'e_c' => 'Video',
            'e_a' => 'Play',
            'pf_net' => 'invalid-but-ignored',
        ]))->assertOk();
    }

    public function test_rejects_oversized_bulk_request(): void
    {
        $this->bindSite();
        $requests = array_fill(0, 51, '?idsite=1&url=https%3A%2F%2Fexample.test');
        $this->post('/matomo.php', ['requests' => $requests])->assertBadRequest();
    }

    public function test_records_json_bulk_envelopes_and_skips_requests_without_rec(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->url === 'https://example.test/recorded'
                && $request->ipAddress === '127.0.0.0',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->postJson('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Frecorded',
            '?idsite=1&url=https%3A%2F%2Fexample.test%2Fskipped',
        ]])->assertOk();
    }

    public function test_rejects_an_invalid_batch_before_recording_any_items(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fvalid',
            '?rec=1&idsite=0&url=https%3A%2F%2Fexample.test%2Finvalid',
        ]])->assertBadRequest();
    }

    public function test_applies_site_do_not_track_to_nested_requests(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('PrivacyManager.idSite(1).doNotTrackEnabled', '1');
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withHeader('DNT', '1')->post('/matomo.php', ['requests' => [
            '?rec=1&idsite=1&url=https%3A%2F%2Fexample.test%2Fa',
        ]])->assertOk()->assertHeader('Tk', 'N');
    }

    public function test_rejects_incomplete_or_non_numeric_events(): void
    {
        $this->bindSite();

        $this->get($this->url(['e_c' => 'Video']))->assertBadRequest();
        $this->get($this->url(['e_c' => 'Video', 'e_a' => 'Play', 'e_v' => '1e9999']))
            ->assertBadRequest();
    }

    public function test_records_downloads_before_other_action_types(): void
    {
        $this->bindSite(['exclude_unknown_urls' => 1]);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 3
                && $request->url === 'https://cdn.example.test/file.zip?token=kept'
                && $request->eventCategory === null
                && $request->contentName === null,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'download' => 'https://cdn.example.test/file.zip?token=kept',
            'link' => 'https://other.test/',
            'e_c' => 'Video',
            'e_a' => 'Play',
            'c_n' => 'Hero',
        ]))->assertOk();
    }

    public function test_records_outlinks(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->actionType === 2
                && $request->url === 'https://other.test/destination',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['link' => 'https://other.test/destination']))->assertOk();
    }

    public function test_rejects_unknown_website_ids(): void
    {
        $this->bindSite([]);

        $this->get($this->url())->assertBadRequest()
            ->assertSeeText('The requested website does not exist.');
    }

    public function test_honors_do_not_track_only_when_enabled(): void
    {
        $this->mutableOptions()->set('PrivacyManager.doNotTrackEnabled', '1');
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withHeader('DNT', '1')->get($this->url())
            ->assertOk()
            ->assertHeader('Tk', 'N');
    }

    public function test_records_do_not_track_request_when_support_is_disabled(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withHeader('DNT', '1')->get($this->url())->assertOk()->assertHeaderMissing('Tk');
    }

    public function test_honors_only_a_valid_ignore_cookie(): void
    {
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withUnencryptedCookie('matomo_ignore', MatomoCookie::encode(['ignore' => '*']))
            ->get($this->url())
            ->assertOk();
    }

    public function test_does_not_treat_an_unset_ignore_cookie_as_opt_out(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withUnencryptedCookie('matomo_ignore', 'invalid')->get($this->url())->assertOk();
    }

    public function test_does_not_treat_a_raw_star_ignore_cookie_as_opt_out(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withUnencryptedCookie('matomo_ignore', '*')->get($this->url())->assertOk();
    }

    public function test_reads_and_sets_the_third_party_visitor_cookie(): void
    {
        $this->bindSite();
        $this->enableThirdPartyCookies();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitorId === 'fedcba9876543210'
                && $request->visitorCookie !== null
                && $request->visitorCookie->name === '_pk_uid'
                && $request->visitorCookie->value === MatomoCookie::encode([0 => 'fedcba9876543210'])
                && $request->visitorCookie->path === ''
                && $request->visitorCookie->domain === ''
                && ! $request->visitorCookie->secure
                && $request->visitorCookie->sameSite === 'Lax',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $response = $this->withUnencryptedCookie('_pk_uid', MatomoCookie::encode([0 => 'fedcba9876543210']))
            ->get($this->url())
            ->assertOk()
            ->assertHeader('P3P', MatomoCookie::P3P_POLICY);

        $cookie = (string) $response->headers->get('Set-Cookie');
        $this->assertStringContainsString('_pk_uid=0%3DZmVkY2JhOTg3NjU0MzIxMA%3D%3D', $cookie);
        $this->assertStringContainsString('; SameSite=Lax', $cookie);
        $this->assertStringNotContainsString('; secure', $cookie);
    }

    public function test_prefers_the_third_party_cookie_over_the_first_party_id(): void
    {
        $this->bindSite();
        $this->enableThirdPartyCookies();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitorId === 'aaaaaaaaaaaaaaaa',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withUnencryptedCookie('_pk_uid', MatomoCookie::encode([0 => 'aaaaaaaaaaaaaaaa']))
            ->get($this->url(['_id' => '0123456789abcdef']))
            ->assertOk();
    }

    public function test_sets_a_secure_none_cookie_over_https(): void
    {
        $this->bindSite();
        $this->enableThirdPartyCookies("cookie_path = \"/\"\ncookie_domain = \"www.analytics.test\"");
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitorCookie !== null
                && $request->visitorCookie->secure
                && $request->visitorCookie->sameSite === 'None'
                && $request->visitorCookie->path === '/'
                && $request->visitorCookie->domain === 'www.analytics.test',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get('https://localhost'.$this->url())->assertOk();
    }

    public function test_does_not_set_or_read_cookies_when_cookieless_tracking_is_forced(): void
    {
        $this->bindSite();
        $this->enableThirdPartyCookies();
        $this->mutableOptions()->set('PrivacyManager.forceCookielessTracking', '1');
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitorId !== '0123456789abcdef'
                && $request->visitorId !== 'fedcba9876543210'
                && $request->visitorCookie === null,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withUnencryptedCookie('_pk_uid', MatomoCookie::encode([0 => 'fedcba9876543210']))
            ->get($this->url())
            ->assertOk()
            ->assertHeaderMissing('Set-Cookie')
            ->assertHeaderMissing('P3P');
    }

    public function test_stores_geolocation_from_the_raw_ip_by_default(): void
    {
        $this->bindSite();
        $locations = $this->createMock(GeolocationProviderRegistry::class);
        $locations->expects($this->once())->method('locate')->with(
            '127.0.0.1',
            'en-us',
            '127.0.0.1',
        )->willReturn([
            'country_code' => 'FR',
            'region_code' => 'IDF',
            'city_name' => 'Paris',
            'lat' => 48.8566,
            'long' => 2.3522,
        ]);
        $this->app->instance(GeolocationProviderRegistry::class, $locations);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->location?->country === 'fr'
                && $request->location->region === 'IDF'
                && $request->location->city === 'Paris'
                && $request->ipAddress === '127.0.0.0',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url())->assertOk();
    }

    public function test_looks_up_location_with_the_anonymized_ip_when_configured(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('PrivacyManager.useAnonymizedIpForVisitEnrichment', '1');
        $locations = $this->createMock(GeolocationProviderRegistry::class);
        $locations->expects($this->once())->method('locate')->with(
            '127.0.0.0',
            'en-us',
            '127.0.0.1',
        )->willReturn(['country_code' => 'xx']);
        $this->app->instance(GeolocationProviderRegistry::class, $locations);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url())->assertOk();
    }

    public function test_parses_user_agent_overrides_and_device_plugins(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->userAgent === 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36'
                && $request->device !== null
                && $request->device->browserName === 'CH'
                && $request->device->operatingSystem === 'WIN'
                && $request->device->pdf
                && ! $request->device->java,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'pdf' => '1',
            'java' => '0',
        ]))->assertOk();
    }

    public function test_uses_a_forced_visitor_id_before_cookies_and_request_ids(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitorId === 'aaaaaaaaaaaaaaaa',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'cid' => 'aaaaaaaaaaaaaaaa',
            '_id' => '0123456789abcdef',
        ]))->assertOk();
    }

    public function test_rejects_an_invalid_forced_visitor_id(): void
    {
        $this->bindSite();
        $this->get($this->url(['cid' => 'short']))
            ->assertBadRequest()
            ->assertSeeText('Visitor ID (cid) short must be 16 characters long');
    }

    public function test_hashes_the_user_id_over_the_visitor_id_when_configured(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitorId === substr(sha1('alice'), 0, 16)
                && $request->userId === 'alice',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['uid' => 'alice', 'cid' => 'aaaaaaaaaaaaaaaa']))->assertOk();
    }

    public function test_rejects_a_custom_ip_without_tracking_authentication(): void
    {
        $this->bindSite();
        $this->get($this->url(['cip' => '203.0.113.10']))
            ->assertBadRequest()
            ->assertSeeText("Tracker API 'cip' was used, requires valid token_auth");
    }

    public function test_uses_a_custom_ip_when_the_token_can_write_the_site(): void
    {
        $this->bindSite();
        $this->grantTrackingOverrides();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->ipAddress === '203.0.0.0',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->post('/matomo.php', $this->parameters(['cip' => '203.0.113.10', 'token_auth' => 'write-token']))
            ->assertOk();
    }

    public function test_records_a_recent_custom_timestamp_without_a_token(): void
    {
        $this->bindSite();
        $recordedAt = time() - 60;
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->recordedAt?->getTimestamp() === $recordedAt,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['cdt' => (string) $recordedAt]))->assertOk();
    }

    public function test_rejects_an_old_custom_timestamp_without_tracking_authentication(): void
    {
        $this->bindSite();
        $recordedAt = time() - 200_000;
        $this->get($this->url(['cdt' => (string) $recordedAt]))
            ->assertBadRequest()
            ->assertSeeText('requires &token_auth');
    }

    public function test_attributes_goal_conversions_from_campaign_cookies(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->referrerType === 3
                && $request->referrerName === 'news.example'
                && $request->conversionReferrerType === 6
                && $request->conversionReferrerName === 'spring'
                && $request->conversionReferrerKeyword === 'shoes',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'urlref' => 'https://news.example/story',
            '_rcn' => 'Spring',
            '_rck' => 'Shoes',
        ]))->assertOk();
    }

    public function test_attributes_goal_conversions_from_the_referrer_cookie(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->referrerType === 1
                && $request->conversionReferrerType === 2
                && $request->conversionReferrerName === 'Google',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            '_ref' => 'https://www.google.com/search?q=blue+shoes',
        ]))->assertOk();
    }

    public function test_forces_a_new_visit_from_the_new_visit_parameter(): void
    {
        $this->bindSite(['timezone' => 'Europe/Paris']);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->forceNewVisit
                && $request->hasKnownVisitorId
                && $request->timezone === 'Europe/Paris',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['new_visit' => '1']))->assertOk();
    }

    public function test_marks_generated_visitor_ids_as_unknown(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->hasKnownVisitorId === false
                && $request->forcedVisitorId === false
                && preg_match('/^[a-f0-9]{16}$/D', $request->visitorId) === 1,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['_id' => '']))->assertOk();
    }

    public function test_does_not_set_a_visitor_cookie_when_third_party_cookies_are_disabled(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->visitorId === '0123456789abcdef'
                && $request->visitorCookie === null,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url())->assertOk()->assertHeaderMissing('Set-Cookie');
    }

    public function test_silently_excludes_prefetch_requests(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withHeaders(['X-Purpose' => 'preview'])->get($this->url())->assertOk();
        $this->withHeaders(['X-Purpose' => 'instant'])->get($this->url())->assertOk();
        $this->withHeaders(['X-Moz' => 'prefetch'])->get($this->url())->assertOk();
    }

    public function test_silently_excludes_known_bot_ip_ranges_unless_bots_are_allowed(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withServerVariables(['REMOTE_ADDR' => '66.249.80.1'])->get($this->url())->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '66.249.80.1'])
            ->get($this->url(['bots' => '1']))
            ->assertOk();
    }

    public function test_records_chrome_data_saver_requests_from_google_ip_ranges(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->withServerVariables([
            'REMOTE_ADDR' => '66.249.80.1',
            'HTTP_VIA' => '1.1 Chrome-Compression-Proxy',
        ])->get($this->url())->assertOk();
    }

    public function test_silently_excludes_search_bots_unless_bots_are_allowed(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']))
            ->assertOk();
    }

    public function test_records_search_bots_when_bots_is_enabled(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->device?->isBot === true,
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'bots' => '1',
            'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ]))->assertOk();
    }

    public function test_silently_excludes_known_referrer_spam(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('referrer_spam_blacklist', serialize(['spam.example']));
        $this->app->forgetInstance(ReferrerSpamList::class);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['urlref' => 'https://spam.example/click']))->assertOk();
    }

    public function test_records_known_referrer_spam_when_the_spam_filter_is_disabled(): void
    {
        $this->bindSite();
        $this->mutableOptions()->set('referrer_spam_blacklist', serialize(['spam.example']));
        $this->app->forgetInstance(ReferrerSpamList::class);
        $this->assertNotFalse(file_put_contents(
            $this->configurationPath,
            str_replace(
                '[Tracker]',
                "[Tracker]\nenable_spam_filter = 0",
                (string) file_get_contents($this->configurationPath),
            ),
        ));
        $this->app->forgetInstance(InstallationConfig::class);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url(['urlref' => 'https://spam.example/click']))->assertOk();
    }

    public function test_silently_excludes_configured_ip_addresses(): void
    {
        $this->bindSite(['excluded_ips' => '127.0.0.*']);
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->never())->method('record');
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url())->assertOk();
    }

    public function test_removes_tracking_and_campaign_parameters_from_stored_url(): void
    {
        $this->bindSite();
        $recorder = $this->createMock(VisitRecorder::class);
        $recorder->expects($this->once())->method('record')->with($this->callback(
            static fn (TrackingRequest $request): bool => $request->url === 'https://example.test/page?keep=yes',
        ));
        $this->app->instance(VisitRecorder::class, $recorder);

        $this->get($this->url([
            'url' => 'https://example.test/page?keep=yes&gclid=secret&utm_campaign=private#fragment',
        ]))->assertOk();
    }

    public function test_rejects_unknown_hosts_when_the_site_requires_known_urls(): void
    {
        $this->bindSite(['exclude_unknown_urls' => 1]);

        $this->get($this->url(['url' => 'https://other.test/page']))
            ->assertBadRequest()
            ->assertSeeText('url does not belong to the requested website.');
    }

    private function grantTrackingOverrides(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(true);
        $authorizer->method('siteIdsWithMinimumRole')->willReturn([1]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->forgetInstance(TrackingRequestPolicy::class);
    }

    private function enableThirdPartyCookies(string $extra = ''): void
    {
        $this->assertNotFalse(file_put_contents(
            $this->configurationPath,
            str_replace(
                '[Tracker]',
                "[Tracker]\nuse_third_party_id_cookie = 1\n{$extra}",
                (string) file_get_contents($this->configurationPath),
            ),
        ));
        $this->app->forgetInstance(InstallationConfig::class);
        $this->app->forgetInstance(TrackingRequestPolicy::class);
    }

    /**
     * @param  array<string, int|string|null>  $overrides
     * @param  list<string>  $urls
     */
    private function bindSite(array $overrides = ['idsite' => 1], array $urls = ['https://example.test']): void
    {
        $details = $overrides === [] ? [] : [
            'idsite' => 1,
            'main_url' => 'https://example.test',
            'exclude_unknown_urls' => 0,
            'excluded_ips' => '',
            'excluded_user_agents' => '',
            'excluded_parameters' => '',
            'keep_url_fragment' => 0,
            ...$overrides,
        ];
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn($details);
        $sites->method('urls')->willReturn($urls);
        $sites->method('excludedReferrers')->willReturn(
            is_string($details['excluded_referrers'] ?? null) ? $details['excluded_referrers'] : null,
        );
        $this->app->instance(SiteRepository::class, $sites);
    }

    /** @param list<array<string, bool|int|string|list<array<string, mixed>>>> $dimensions */
    private function bindDimensions(array $dimensions): void
    {
        $customDimensions = $this->createStub(CustomDimensionRepository::class);
        $customDimensions->method('configuredForSite')->willReturn($dimensions);
        $this->app->instance(CustomDimensionRepository::class, $customDimensions);
    }

    private function mutableOptions(): MutableOptionRepository
    {
        return $this->app->make(MutableOptionRepository::class);
    }

    /** @param array<string, string> $parameters */
    private function url(array $parameters = []): string
    {
        return '/matomo.php?'.http_build_query($this->parameters($parameters));
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, string>
     */
    private function parameters(array $parameters = []): array
    {
        return [
            'rec' => '1',
            'idsite' => '1',
            'url' => 'https://example.test/page',
            '_id' => '0123456789abcdef',
            ...$parameters,
        ];
    }
}
