<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Config\InstallationConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InstallationConfigTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            unlink($temporaryFile);
        }

        parent::tearDown();
    }

    public function test_loads_the_existing_matomo_database_and_auth_settings(): void
    {
        $configuration = InstallationConfig::fromFile($this->configurationFile(
            tablesPrefix: 'matomo_',
            secureTokens: '1',
        ));

        $this->assertSame('test_database', $configuration->databaseConnection()['database']);
        $this->assertSame('matomo_', $configuration->databaseConnection()['prefix']);
        $this->assertSame('secret-salt', $configuration->salt());
        $this->assertTrue($configuration->onlyAllowSecureTokens());
        $this->assertSame(-1, $configuration->apiBulkRequestLimit());
        $this->assertSame(1_209_600, $configuration->sessionLifetime());
        $this->assertSame(3_600, $configuration->sessionIdleTimeout());
        $this->assertSame(['10.0.0.0/8'], $configuration->loginAllowlistIps());
        $this->assertTrue($configuration->loginAllowlistAppliesToReportingApi());
        $this->assertSame(['HTTP_X_FORWARDED_FOR'], $configuration->proxyClientHeaders());
        $this->assertSame(['10.0.0.1'], $configuration->proxyIps());
        $this->assertTrue($configuration->proxyIpReadLastInList());
        $this->assertFalse($configuration->internetFeaturesEnabled());
        $this->assertSame(['192.168.1.0/24'], $configuration->allowedPrivateEgressRanges());
        $this->assertSame('proxy.example', $configuration->outboundProxyHost());
        $this->assertSame(
            ['direct.example', 'other.example'],
            $configuration->outboundProxyExcludedHosts(),
        );
        $this->assertSame(21, $configuration->websitesCountToDisplay());
        $this->assertSame(['CoreHome', 'SitesManager'], $configuration->activatedPlugins());
        $this->assertSame(['BTC' => 'Bitcoin'], $configuration->customCurrencies());
        $this->assertTrue($configuration->configuredCnilPolicy());
        $this->assertFalse($configuration->configuredFilterPiiEnforcement());
        $this->assertFalse($configuration->thirdPartyCookiesEnabled());
        $this->assertSame(180, $configuration->deleteLogsOlderThan());
        $this->assertTrue($configuration->granularPrivacyComplianceEnabled());
        $this->assertSame(['email', 'password'], $configuration->commonPiiParameters());
        $this->assertSame('fr', $configuration->defaultLanguage());
        $this->assertSame('language_cookie', $configuration->languageCookieName());
        $this->assertSame('product@example.test', $configuration->feedbackEmailAddress());
        $this->assertFalse($configuration->emailsEnabled());
        $this->assertSame('reports@{DOMAIN}', $configuration->noReplyEmailAddress());
        $this->assertSame('Analytics Reports', $configuration->noReplyEmailName());
        $this->assertSame(['en', 'fr'], $configuration->availableLanguages());
        $this->assertTrue($configuration->uniqueVisitorsEnabled('day'));
        $this->assertFalse($configuration->uniqueVisitorsEnabled('year'));
        $this->assertTrue($configuration->reportingPeriodEnabled('range'));
        $this->assertSame(
            ['countryCode==fr', 'browserCode==FF'],
            $configuration->autoArchiveSegments(),
        );
        $this->assertTrue($configuration->anonymousSegmentsEnabled());
        $this->assertSame(12, $configuration->configuredLoginMaxAllowedRetries());
        $this->assertSame(45, $configuration->configuredLoginAllowedRetriesTimeRange());
        $this->assertSame(['10.1.*.*'], $configuration->configuredLoginBruteForceAllowlist());
        $this->assertTrue($configuration->usersAdminEnabled());
        $this->assertTrue($configuration->sitesAdminEnabled());
        $this->assertTrue($configuration->generalSettingsAdminEnabled());
        $this->assertTrue($configuration->geolocationAdminEnabled());
        $this->assertTrue($configuration->customLogoEnabled());
        $this->assertTrue($configuration->browserArchivingTriggerEnabled());
        $this->assertTrue($configuration->defaultLocationProviderEnabled());
        $this->assertTrue($configuration->languageToCountryGuessEnabled());
        $this->assertTrue($configuration->professionalServicesAdsEnabled());
        $this->assertFalse($configuration->developmentModeEnabled());
        $this->assertSame('tenant.example', $configuration->instanceId());
        $this->assertSame('/custom-tmp', $configuration->temporaryPath());
        $this->assertSame(['analytics.example', 'reports.example'], $configuration->trustedHosts());
        $this->assertFalse($configuration->trustedHostCheckEnabled());
        $this->assertSame('month', $configuration->transitionsMaxPeriodAllowed(2));
        $this->assertSame('week', $configuration->transitionsMaxPeriodAllowed(7));
        $this->assertSame([
            'defaultProvider' => 'openai',
            'openaiApiKey' => 'managed-key',
        ], $configuration->aiProviders());
        $this->assertSame([
            'time_network' => 120,
            'time_server' => 0,
            'time_transfer' => 0,
            'time_dom_processing' => 0,
            'time_dom_completion' => 0,
            'time_on_load' => 0,
        ], $configuration->pagePerformanceTimingCaps());
        $this->assertSame(300, $configuration->overlayFollowingPagesLimit());
        $this->assertContains('token_auth', $configuration->urlQueryParametersToExclude());
        $this->assertContains('utm_campaign', $configuration->campaignNameParameters());
        $this->assertContains('utm_term', $configuration->campaignKeywordParameters());
        $this->assertSame(1024, $configuration->pageMaximumLength());
        $this->assertSame(100, $configuration->liveAiChatbotsMaximumRows());
        $this->assertSame(100, $configuration->liveAiChatbotsTopPageUrlsMaximumRows());
        $this->assertSame(-1.0, $configuration->liveQueryMaximumExecutionTime());
        $this->assertSame(100, $configuration->liveVisitorProfileMaximumVisits());
    }

    public function test_loads_reporting_overrides(): void
    {
        $configuration = InstallationConfig::fromFile($this->configurationFile(
            tablesPrefix: 'matomo_',
            extraGeneral: <<<'INI'
            enable_processing_unique_visitors_day = 0
            enable_processing_unique_visitors_year = 1
            enabled_periods_API = "day,month"
            anonymous_user_enable_use_segments_API = 0
            enable_users_admin = 0
            enable_sites_admin = 0
            enable_general_settings_admin = 0
            enable_geolocation_admin = 0
            enable_custom_logo = 0
            enable_browser_archiving_triggering = 0
            piwik_professional_support_ads_enabled = 0
            overlay_following_pages_limit = 25
            live_ai_chatbots_maximum_rows = 17
            live_ai_chatbots_top_page_urls_maximum_rows = 23
            live_query_max_execution_time = 1.5
            live_visitor_profile_max_visits_to_aggregate = 31
            INI,
            extraTracker: <<<'INI'
            enable_default_location_provider = 0
            enable_language_to_country_guess = 0
            url_query_parameter_to_exclude_from_url = "session,secret"
            campaign_var_name = "campaign"
            campaign_keyword_var_name = "keyword"
            page_maximum_length = 2048

            [Tracker_7]
            use_third_party_id_cookie = 1
            INI,
        ));

        $this->assertFalse($configuration->uniqueVisitorsEnabled('day'));
        $this->assertTrue($configuration->uniqueVisitorsEnabled('year'));
        $this->assertTrue($configuration->reportingPeriodEnabled('month'));
        $this->assertFalse($configuration->reportingPeriodEnabled('week'));
        $this->assertFalse($configuration->anonymousSegmentsEnabled());
        $this->assertFalse($configuration->usersAdminEnabled());
        $this->assertFalse($configuration->sitesAdminEnabled());
        $this->assertFalse($configuration->generalSettingsAdminEnabled());
        $this->assertFalse($configuration->geolocationAdminEnabled());
        $this->assertFalse($configuration->customLogoEnabled());
        $this->assertFalse($configuration->browserArchivingTriggerEnabled());
        $this->assertFalse($configuration->defaultLocationProviderEnabled());
        $this->assertFalse($configuration->languageToCountryGuessEnabled());
        $this->assertFalse($configuration->professionalServicesAdsEnabled());
        $this->assertSame(25, $configuration->overlayFollowingPagesLimit());
        $this->assertSame(17, $configuration->liveAiChatbotsMaximumRows());
        $this->assertSame(23, $configuration->liveAiChatbotsTopPageUrlsMaximumRows());
        $this->assertSame(1.5, $configuration->liveQueryMaximumExecutionTime());
        $this->assertSame(31, $configuration->liveVisitorProfileMaximumVisits());
        $this->assertSame(['session', 'secret'], $configuration->urlQueryParametersToExclude());
        $this->assertSame(['campaign'], $configuration->campaignNameParameters());
        $this->assertSame(['keyword'], $configuration->campaignKeywordParameters());
        $this->assertSame(2048, $configuration->pageMaximumLength());
        $this->assertTrue($configuration->thirdPartyCookiesEnabled(7));
        $this->assertFalse($configuration->thirdPartyCookiesEnabled(8));
    }

    public function test_loads_site_specific_segment_creation_access(): void
    {
        $configuration = InstallationConfig::fromFile($this->configurationFile(
            tablesPrefix: 'matomo_',
            extraGeneral: <<<'INI'
            adding_segment_requires_access = "write"
            allow_adding_segments_for_all_websites = 0
            enable_create_realtime_segments = 0
            browser_archiving_disabled_enforce = 1
            process_new_segments_from = "editLast12"

            [General_7]
            adding_segment_requires_access = "admin"
            INI,
        ));

        $this->assertSame('write', $configuration->segmentCreationAccess());
        $this->assertSame('write', $configuration->segmentCreationAccess(6));
        $this->assertSame('admin', $configuration->segmentCreationAccess(7));
        $this->assertFalse($configuration->segmentAllSitesAllowed());
        $this->assertFalse($configuration->realtimeSegmentsAllowed());
        $this->assertFalse($configuration->browserArchivingAvailableForSegments());
        $this->assertSame('editLast12', $configuration->processNewSegmentsFrom());
    }

    public function test_rejects_unknown_segment_creation_access(): void
    {
        $configuration = InstallationConfig::fromFile($this->configurationFile(
            tablesPrefix: 'matomo_',
            extraGeneral: 'adding_segment_requires_access = "owner"',
        ));

        $this->assertSame('none', $configuration->segmentCreationAccess());
    }

    public function test_rejects_unsafe_table_prefix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Matomo database table prefix is invalid.');

        InstallationConfig::fromFile($this->configurationFile(tablesPrefix: 'matomo`; DROP TABLE user;'));
    }

    public function test_legacy_professional_service_ads_setting_can_enable_ads(): void
    {
        $configuration = InstallationConfig::fromFile($this->configurationFile(
            tablesPrefix: 'matomo_',
            extraGeneral: <<<'INI'
            piwik_professional_support_ads_enabled = 0
            piwik_pro_ads_enabled = 1
            INI,
        ));

        $this->assertTrue($configuration->professionalServicesAdsEnabled());
    }

    private function configurationFile(
        string $tablesPrefix,
        string $secureTokens = '0',
        string $extraGeneral = '',
        string $extraTracker = '',
    ): string {
        $path = tempnam('/dev/shm', 'matomo-config-');
        $this->assertIsString($path);
        $this->temporaryFiles[] = $path;
        $content = <<<INI
            ; <?php exit; ?> DO NOT REMOVE THIS LINE
            [database]
            host = "database"
            username = "matomo"
            password = "password"
            dbname = "test_database"
            tables_prefix = "{$tablesPrefix}"
            port = 3306
            adapter = "PDO\\MYSQL"
            charset = "utf8mb4"

            [General]
            salt = "secret-salt"
            only_allow_secure_auth_tokens = {$secureTokens}
            login_allowlist_ip[] = "10.0.0.0/8"
            proxy_client_headers[] = "HTTP_X_FORWARDED_FOR"
            proxy_ips[] = "10.0.0.1"
            enable_internet_features = 0
            allowed_private_egress_ranges[] = "192.168.1.0/24"
            autocomplete_min_sites = 9
            site_selector_max_sites = 21
            currencies[BTC] = "Bitcoin"
            default_language = "fr"
            language_cookie_name = "language_cookie"
            feedback_email_address = "product@example.test"
            emails_enabled = 0
            noreply_email_address = "reports@{DOMAIN}"
            noreply_email_name = "Analytics Reports"
            instance_id = "tenant.example/< >"
            tmp_path = "/custom-tmp"
            trusted_hosts[] = "analytics.example"
            trusted_hosts[] = "reports.example"
            enable_trusted_host_check = 0
            {$extraGeneral}

            [Plugins]
            Plugins[] = "CoreHome"
            Plugins[] = "SitesManager"

            [CnilPolicy]
            cnil_v1_policy_enabled = 1

            [SitesManager]
            FilterPIIParameters_policy_enforced = 0
            CommonPIIParams[] = "email"
            CommonPIIParams[] = "password"

            [FeatureFlags]
            GranularPrivacyCompliance_feature = "enabled"

            [Languages]
            Languages[] = "en"
            Languages[] = "fr"

            [Login]
            maxAllowedRetries = 12
            allowedRetriesTimeRange = 45
            whitelisteBruteForceIps[] = "10.1.*.*"

            [proxy]
            host = "proxy.example"
            exclude = "direct.example, other.example"

            [Tracker]
            {$extraTracker}

            [AIProviders]
            defaultProvider = "openai"
            openaiApiKey = "managed-key"

            [PagePerformance]
            time_network_cap_duration_ms = 120
            time_server_cap_duration_ms = -1

            [Segments]
            Segments[] = "countryCode==fr"
            Segments[] = "browserCode==FF"

            [Transitions]
            max_period_allowed = "month"

            [Transitions_7]
            max_period_allowed = "week"
            INI;

        $this->assertNotFalse(file_put_contents($path, $content));

        return $path;
    }
}
