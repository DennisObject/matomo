<?php

declare(strict_types=1);

namespace App\Matomo\Config;

use RuntimeException;

final readonly class InstallationConfig
{
    /**
     * @param  array<string, mixed>  $databaseConnection
     */
    private function __construct(
        private array $databaseConnection,
        #[\SensitiveParameter]
        private string $salt,
        private bool $onlyAllowSecureTokens,
        private int $sessionLifetime,
        private int $sessionIdleTimeout,
        /** @var list<string> */
        private array $loginAllowlistIps,
        private bool $loginAllowlistAppliesToReportingApi,
        /** @var list<string> */
        private array $proxyClientHeaders,
        /** @var list<string> */
        private array $proxyIps,
        private bool $proxyIpReadLastInList,
        private bool $internetFeaturesEnabled,
        /** @var list<string> */
        private array $allowedPrivateEgressRanges,
        private ?string $outboundProxyHost,
        /** @var list<string> */
        private array $outboundProxyExcludedHosts,
        private int $websitesCountToDisplay,
        /** @var list<string> */
        private array $activatedPlugins,
        /** @var array<string, string> */
        private array $customCurrencies,
        private ?bool $configuredCnilPolicy,
        private ?bool $configuredFilterPiiEnforcement,
        /** @var list<string>|null */
        private ?array $commonPiiParameters,
        private string $defaultLanguage,
        private string $defaultReportDate,
        private string $languageCookieName,
        private string $feedbackEmailAddress,
        private bool $emailsEnabled,
        private string $noReplyEmailAddress,
        private string $noReplyEmailName,
        /** @var list<string>|null */
        private ?array $availableLanguages,
        /** @var array<string, bool> */
        private array $uniqueVisitorsByPeriod,
        /** @var list<string> */
        private array $enabledReportingPeriods,
        /** @var list<string> */
        private array $autoArchiveSegments,
        private bool $anonymousSegmentsEnabled,
        private ?int $configuredLoginMaxAllowedRetries,
        private ?int $configuredLoginAllowedRetriesTimeRange,
        /** @var list<string>|null */
        private ?array $configuredLoginBruteForceAllowlist,
        private bool $usersAdminEnabled,
        private bool $sitesAdminEnabled,
        private bool $generalSettingsAdminEnabled,
        private bool $geolocationAdminEnabled,
        private bool $customLogoEnabled,
        private bool $browserArchivingTriggerEnabled,
        private bool $defaultLocationProviderEnabled,
        private bool $languageToCountryGuessEnabled,
        private bool $professionalServicesAdsEnabled,
        private bool $developmentModeEnabled,
        private string $instanceId,
        private string $temporaryPath,
        /** @var list<string> */
        private array $trustedHosts,
        private bool $trustedHostCheckEnabled,
        /** @var array<int, string> */
        private array $transitionsMaxPeriodAllowed,
        /** @var array<string, mixed> */
        private array $aiProviders,
        /** @var array<string, int> */
        private array $pagePerformanceTimingCaps,
        private int $overlayFollowingPagesLimit,
        /** @var list<string> */
        private array $urlQueryParametersToExclude,
        /** @var list<string> */
        private array $campaignNameParameters,
        /** @var list<string> */
        private array $campaignKeywordParameters,
        private int $pageMaximumLength,
        private int $liveAiChatbotsMaximumRows,
        private int $liveAiChatbotsTopPageUrlsMaximumRows,
        private float $liveQueryMaximumExecutionTime,
        private string $segmentCreationAccess,
        /** @var array<int, string> */
        private array $siteSegmentCreationAccess,
        private bool $segmentAllSitesAllowed,
        private bool $realtimeSegmentsAllowed,
        private bool $browserArchivingAvailableForSegments,
        private string $processNewSegmentsFrom,
        private int $deleteLogsMaxRowsPerQuery,
        private int $deleteLogsUnusedActionsMaxRowsPerQuery,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The Matomo configuration file is not readable.');
        }

        $configuration = parse_ini_file($path, true, INI_SCANNER_RAW);

        if (! is_array($configuration)) {
            throw new RuntimeException('The Matomo configuration file is invalid.');
        }

        $database = $configuration['database'] ?? null;
        $general = $configuration['General'] ?? [];
        $plugins = $configuration['Plugins'] ?? [];
        $cnilPolicy = $configuration['CnilPolicy'] ?? [];
        $sitesManager = $configuration['SitesManager'] ?? [];
        $languages = $configuration['Languages'] ?? [];
        $login = $configuration['Login'] ?? [];
        $proxy = $configuration['proxy'] ?? [];
        $tracker = $configuration['Tracker'] ?? [];
        $development = $configuration['Development'] ?? [];
        $aiProviders = $configuration['AIProviders'] ?? [];
        $segments = $configuration['Segments'] ?? [];
        $pagePerformance = $configuration['PagePerformance'] ?? [];
        $deleteLogs = $configuration['Deletelogs'] ?? [];

        if (! is_array($database)
            || ! is_array($general)
            || ! is_array($plugins)
            || ! is_array($cnilPolicy)
            || ! is_array($sitesManager)
            || ! is_array($languages)
            || ! is_array($login)
            || ! is_array($proxy)
            || ! is_array($tracker)
            || ! is_array($development)
            || ! is_array($aiProviders)
            || ! is_array($segments)
            || ! is_array($pagePerformance)
            || ! is_array($deleteLogs)) {
            throw new RuntimeException('The Matomo configuration is missing required sections.');
        }

        $adapter = strtolower(self::string($database, 'adapter', 'pdo\\mysql'));

        if (! str_contains($adapter, 'mysql')) {
            throw new RuntimeException('The Matomo database adapter is not supported yet.');
        }

        $prefix = self::string($database, 'tables_prefix');

        if (preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) !== 1) {
            throw new RuntimeException('The Matomo database table prefix is invalid.');
        }

        $salt = self::string($general, 'salt');

        if ($salt === '') {
            throw new RuntimeException('The Matomo authentication salt is missing.');
        }

        return new self(
            databaseConnection: self::buildDatabaseConnection($database, $prefix),
            salt: $salt,
            onlyAllowSecureTokens: self::boolean($general, 'only_allow_secure_auth_tokens'),
            sessionLifetime: self::positiveInteger($general, 'login_cookie_expire', 1_209_600),
            sessionIdleTimeout: self::positiveInteger(
                $general,
                'login_session_not_remembered_idle_timeout',
                3_600,
            ),
            loginAllowlistIps: self::parseLoginAllowlistIps($general),
            loginAllowlistAppliesToReportingApi: self::boolean(
                $general,
                'login_allowlist_apply_to_reporting_api_requests',
                true,
            ) || self::boolean($general, 'login_whitelist_apply_to_reporting_api_requests'),
            proxyClientHeaders: self::stringList($general, 'proxy_client_headers'),
            proxyIps: self::stringList($general, 'proxy_ips'),
            proxyIpReadLastInList: self::boolean($general, 'proxy_ip_read_last_in_list', true),
            internetFeaturesEnabled: self::boolean($general, 'enable_internet_features', true),
            allowedPrivateEgressRanges: self::stringList($general, 'allowed_private_egress_ranges'),
            outboundProxyHost: self::nullableString($proxy, 'host'),
            outboundProxyExcludedHosts: self::commaSeparatedList($proxy, 'exclude'),
            websitesCountToDisplay: max(
                self::positiveInteger($general, 'site_selector_max_sites', 15),
                self::positiveInteger($general, 'autocomplete_min_sites', 5),
            ),
            activatedPlugins: self::stringList($plugins, 'Plugins'),
            customCurrencies: self::stringMap($general, 'currencies'),
            configuredCnilPolicy: self::nullableBoolean($cnilPolicy, 'cnil_v1_policy_enabled'),
            configuredFilterPiiEnforcement: self::nullableBoolean(
                $sitesManager,
                'FilterPIIParameters_policy_enforced',
            ),
            commonPiiParameters: self::nullableStringList($sitesManager, 'CommonPIIParams'),
            defaultLanguage: strtolower(self::string($general, 'default_language', 'en')),
            defaultReportDate: self::string($general, 'default_day', 'yesterday'),
            languageCookieName: self::string($general, 'language_cookie_name', 'matomo_lang'),
            feedbackEmailAddress: self::string(
                $general,
                'feedback_email_address',
                'feedback@matomo.org',
            ),
            emailsEnabled: self::boolean($general, 'emails_enabled', true),
            noReplyEmailAddress: self::string(
                $general,
                'noreply_email_address',
                'noreply@{DOMAIN}',
            ),
            noReplyEmailName: self::string($general, 'noreply_email_name'),
            availableLanguages: self::nullableStringList($languages, 'Languages'),
            uniqueVisitorsByPeriod: self::uniqueVisitorsByPeriod($general),
            enabledReportingPeriods: self::commaSeparatedList(
                $general,
                'enabled_periods_API',
                ['day', 'week', 'month', 'year', 'range'],
            ),
            autoArchiveSegments: self::stringList($segments, 'Segments'),
            anonymousSegmentsEnabled: self::boolean(
                $general,
                'anonymous_user_enable_use_segments_API',
                true,
            ),
            configuredLoginMaxAllowedRetries: self::nullablePositiveInteger($login, 'maxAllowedRetries'),
            configuredLoginAllowedRetriesTimeRange: self::nullablePositiveInteger(
                $login,
                'allowedRetriesTimeRange',
            ),
            configuredLoginBruteForceAllowlist: self::nullableStringList(
                $login,
                'whitelisteBruteForceIps',
            ),
            usersAdminEnabled: self::boolean($general, 'enable_users_admin', true),
            sitesAdminEnabled: self::boolean($general, 'enable_sites_admin', true),
            generalSettingsAdminEnabled: self::boolean(
                $general,
                'enable_general_settings_admin',
                true,
            ),
            geolocationAdminEnabled: self::boolean($general, 'enable_geolocation_admin', true),
            customLogoEnabled: self::boolean($general, 'enable_custom_logo', true),
            browserArchivingTriggerEnabled: self::boolean(
                $general,
                'enable_browser_archiving_triggering',
                true,
            ),
            defaultLocationProviderEnabled: self::boolean(
                $tracker,
                'enable_default_location_provider',
                true,
            ),
            languageToCountryGuessEnabled: self::boolean(
                $tracker,
                'enable_language_to_country_guess',
                true,
            ),
            professionalServicesAdsEnabled: self::boolean(
                $general,
                'piwik_professional_support_ads_enabled',
                true,
            ) || self::boolean($general, 'piwik_pro_ads_enabled'),
            developmentModeEnabled: self::boolean($development, 'enabled'),
            instanceId: preg_replace(
                '/[^\w.-]/',
                '',
                self::string($general, 'instance_id'),
            ) ?? '',
            temporaryPath: self::parsedTemporaryPath($general),
            trustedHosts: self::stringList($general, 'trusted_hosts'),
            trustedHostCheckEnabled: self::boolean($general, 'enable_trusted_host_check', true),
            transitionsMaxPeriodAllowed: self::parseTransitionsMaxPeriodAllowed($configuration),
            aiProviders: $aiProviders,
            pagePerformanceTimingCaps: self::parsePagePerformanceTimingCaps($pagePerformance),
            overlayFollowingPagesLimit: self::nonNegativeInteger(
                $general,
                'overlay_following_pages_limit',
                300,
            ),
            urlQueryParametersToExclude: self::commaSeparatedList(
                $tracker,
                'url_query_parameter_to_exclude_from_url',
                [
                    'gclid',
                    'fbclid',
                    'msclkid',
                    'twclid',
                    'wbraid',
                    'gbraid',
                    'yclid',
                    'fb_xd_fragment',
                    'fb_comment_id',
                    'phpsessid',
                    'jsessionid',
                    'sessionid',
                    'aspsessionid',
                    'doing_wp_cron',
                    'sid',
                    'pk_vid',
                    'li_fat_id',
                    'token_auth',
                    'token',
                ],
            ),
            campaignNameParameters: self::commaSeparatedList(
                $tracker,
                'campaign_var_name',
                [
                    'pk_cpn',
                    'pk_campaign',
                    'piwik_campaign',
                    'mtm_campaign',
                    'matomo_campaign',
                    'utm_campaign',
                    'utm_source',
                    'utm_medium',
                ],
            ),
            campaignKeywordParameters: self::commaSeparatedList(
                $tracker,
                'campaign_keyword_var_name',
                [
                    'pk_kwd',
                    'pk_keyword',
                    'piwik_kwd',
                    'mtm_kwd',
                    'mtm_keyword',
                    'matomo_kwd',
                    'utm_term',
                ],
            ),
            pageMaximumLength: self::positiveInteger($tracker, 'page_maximum_length', 1024),
            liveAiChatbotsMaximumRows: self::positiveInteger(
                $general,
                'live_ai_chatbots_maximum_rows',
                100,
            ),
            liveAiChatbotsTopPageUrlsMaximumRows: self::positiveInteger(
                $general,
                'live_ai_chatbots_top_page_urls_maximum_rows',
                100,
            ),
            liveQueryMaximumExecutionTime: self::number(
                $general,
                'live_query_max_execution_time',
                -1.0,
            ),
            segmentCreationAccess: self::parsedSegmentCreationAccess($general),
            siteSegmentCreationAccess: self::siteSegmentCreationAccess($configuration),
            segmentAllSitesAllowed: self::boolean(
                $general,
                'allow_adding_segments_for_all_websites',
                true,
            ),
            realtimeSegmentsAllowed: self::boolean(
                $general,
                'enable_create_realtime_segments',
                true,
            ),
            browserArchivingAvailableForSegments: ! self::boolean(
                $general,
                'browser_archiving_disabled_enforce',
            ),
            processNewSegmentsFrom: self::string(
                $general,
                'process_new_segments_from',
                'beginning_of_time',
            ),
            deleteLogsMaxRowsPerQuery: self::positiveInteger(
                $deleteLogs,
                'delete_logs_max_rows_per_query',
                100_000,
            ),
            deleteLogsUnusedActionsMaxRowsPerQuery: self::positiveInteger(
                $deleteLogs,
                'delete_logs_unused_actions_max_rows_per_query',
                100_000,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function databaseConnection(): array
    {
        return $this->databaseConnection;
    }

    public function salt(): string
    {
        return $this->salt;
    }

    public function onlyAllowSecureTokens(): bool
    {
        return $this->onlyAllowSecureTokens;
    }

    public function sessionLifetime(): int
    {
        return $this->sessionLifetime;
    }

    public function sessionIdleTimeout(): int
    {
        return $this->sessionIdleTimeout;
    }

    /**
     * @return list<string>
     */
    public function loginAllowlistIps(): array
    {
        return $this->loginAllowlistIps;
    }

    public function loginAllowlistAppliesToReportingApi(): bool
    {
        return $this->loginAllowlistAppliesToReportingApi;
    }

    /**
     * @return list<string>
     */
    public function proxyClientHeaders(): array
    {
        return $this->proxyClientHeaders;
    }

    /**
     * @return list<string>
     */
    public function proxyIps(): array
    {
        return $this->proxyIps;
    }

    public function proxyIpReadLastInList(): bool
    {
        return $this->proxyIpReadLastInList;
    }

    public function internetFeaturesEnabled(): bool
    {
        return $this->internetFeaturesEnabled;
    }

    /**
     * @return list<string>
     */
    public function allowedPrivateEgressRanges(): array
    {
        return $this->allowedPrivateEgressRanges;
    }

    public function outboundProxyHost(): ?string
    {
        return $this->outboundProxyHost;
    }

    /**
     * @return list<string>
     */
    public function outboundProxyExcludedHosts(): array
    {
        return $this->outboundProxyExcludedHosts;
    }

    public function websitesCountToDisplay(): int
    {
        return $this->websitesCountToDisplay;
    }

    /**
     * @return list<string>
     */
    public function activatedPlugins(): array
    {
        return $this->activatedPlugins;
    }

    /**
     * @return array<string, string>
     */
    public function customCurrencies(): array
    {
        return $this->customCurrencies;
    }

    public function configuredCnilPolicy(): ?bool
    {
        return $this->configuredCnilPolicy;
    }

    public function configuredFilterPiiEnforcement(): ?bool
    {
        return $this->configuredFilterPiiEnforcement;
    }

    /**
     * @return list<string>|null
     */
    public function commonPiiParameters(): ?array
    {
        return $this->commonPiiParameters;
    }

    public function defaultLanguage(): string
    {
        return $this->defaultLanguage;
    }

    public function defaultReportDate(): string
    {
        return $this->defaultReportDate;
    }

    public function languageCookieName(): string
    {
        return $this->languageCookieName;
    }

    public function feedbackEmailAddress(): string
    {
        return $this->feedbackEmailAddress;
    }

    public function emailsEnabled(): bool
    {
        return $this->emailsEnabled;
    }

    public function noReplyEmailAddress(): string
    {
        return $this->noReplyEmailAddress;
    }

    public function noReplyEmailName(): string
    {
        return $this->noReplyEmailName;
    }

    /**
     * @return list<string>|null
     */
    public function availableLanguages(): ?array
    {
        return $this->availableLanguages;
    }

    public function uniqueVisitorsEnabled(string $period): bool
    {
        return $this->uniqueVisitorsByPeriod[$period] ?? false;
    }

    public function reportingPeriodEnabled(string $period): bool
    {
        return in_array($period, $this->enabledReportingPeriods, true);
    }

    /** @return list<string> */
    public function autoArchiveSegments(): array
    {
        return $this->autoArchiveSegments;
    }

    public function anonymousSegmentsEnabled(): bool
    {
        return $this->anonymousSegmentsEnabled;
    }

    public function configuredLoginMaxAllowedRetries(): ?int
    {
        return $this->configuredLoginMaxAllowedRetries;
    }

    public function configuredLoginAllowedRetriesTimeRange(): ?int
    {
        return $this->configuredLoginAllowedRetriesTimeRange;
    }

    /** @return list<string>|null */
    public function configuredLoginBruteForceAllowlist(): ?array
    {
        return $this->configuredLoginBruteForceAllowlist;
    }

    public function usersAdminEnabled(): bool
    {
        return $this->usersAdminEnabled;
    }

    public function sitesAdminEnabled(): bool
    {
        return $this->sitesAdminEnabled;
    }

    public function generalSettingsAdminEnabled(): bool
    {
        return $this->generalSettingsAdminEnabled;
    }

    public function geolocationAdminEnabled(): bool
    {
        return $this->geolocationAdminEnabled;
    }

    public function customLogoEnabled(): bool
    {
        return $this->customLogoEnabled;
    }

    public function browserArchivingTriggerEnabled(): bool
    {
        return $this->browserArchivingTriggerEnabled;
    }

    public function defaultLocationProviderEnabled(): bool
    {
        return $this->defaultLocationProviderEnabled;
    }

    public function languageToCountryGuessEnabled(): bool
    {
        return $this->languageToCountryGuessEnabled;
    }

    public function professionalServicesAdsEnabled(): bool
    {
        return $this->professionalServicesAdsEnabled;
    }

    public function developmentModeEnabled(): bool
    {
        return $this->developmentModeEnabled;
    }

    public function instanceId(): string
    {
        return $this->instanceId;
    }

    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    /** @return list<string> */
    public function trustedHosts(): array
    {
        return $this->trustedHosts;
    }

    public function trustedHostCheckEnabled(): bool
    {
        return $this->trustedHostCheckEnabled;
    }

    public function transitionsMaxPeriodAllowed(int $idSite): string
    {
        return $this->transitionsMaxPeriodAllowed[$idSite]
            ?? $this->transitionsMaxPeriodAllowed[0]
            ?? 'all';
    }

    /** @return array<string, mixed> */
    public function aiProviders(): array
    {
        return $this->aiProviders;
    }

    /** @return array<string, int> */
    public function pagePerformanceTimingCaps(): array
    {
        return $this->pagePerformanceTimingCaps;
    }

    public function overlayFollowingPagesLimit(): int
    {
        return $this->overlayFollowingPagesLimit;
    }

    /** @return list<string> */
    public function urlQueryParametersToExclude(): array
    {
        return $this->urlQueryParametersToExclude;
    }

    /** @return list<string> */
    public function campaignNameParameters(): array
    {
        return $this->campaignNameParameters;
    }

    /** @return list<string> */
    public function campaignKeywordParameters(): array
    {
        return $this->campaignKeywordParameters;
    }

    public function pageMaximumLength(): int
    {
        return $this->pageMaximumLength;
    }

    public function liveAiChatbotsMaximumRows(): int
    {
        return $this->liveAiChatbotsMaximumRows;
    }

    public function liveAiChatbotsTopPageUrlsMaximumRows(): int
    {
        return $this->liveAiChatbotsTopPageUrlsMaximumRows;
    }

    public function liveQueryMaximumExecutionTime(): float
    {
        return $this->liveQueryMaximumExecutionTime;
    }

    public function segmentCreationAccess(?int $siteId = null): string
    {
        if ($siteId !== null && isset($this->siteSegmentCreationAccess[$siteId])) {
            return $this->siteSegmentCreationAccess[$siteId];
        }

        return $this->segmentCreationAccess;
    }

    public function segmentAllSitesAllowed(): bool
    {
        return $this->segmentAllSitesAllowed;
    }

    public function realtimeSegmentsAllowed(): bool
    {
        return $this->realtimeSegmentsAllowed;
    }

    public function browserArchivingAvailableForSegments(): bool
    {
        return $this->browserArchivingAvailableForSegments;
    }

    public function processNewSegmentsFrom(): string
    {
        return $this->processNewSegmentsFrom;
    }

    public function deleteLogsMaxRowsPerQuery(): int
    {
        return $this->deleteLogsMaxRowsPerQuery;
    }

    public function deleteLogsUnusedActionsMaxRowsPerQuery(): int
    {
        return $this->deleteLogsUnusedActionsMaxRowsPerQuery;
    }

    /** @param array<string, mixed> $general */
    private static function parsedSegmentCreationAccess(array $general): string
    {
        $access = strtolower(self::string($general, 'adding_segment_requires_access', 'view'));

        return in_array($access, ['view', 'write', 'admin', 'superuser'], true) ? $access : 'none';
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, string>
     */
    private static function siteSegmentCreationAccess(array $configuration): array
    {
        $accessBySite = [];

        foreach ($configuration as $section => $values) {
            if (preg_match('/^General_([1-9][0-9]*)$/D', $section, $matches) !== 1
                || ! is_array($values)
                || ! array_key_exists('adding_segment_requires_access', $values)) {
                continue;
            }

            /** @var array<string, mixed> $values */
            $accessBySite[(int) $matches[1]] = self::parsedSegmentCreationAccess($values);
        }

        return $accessBySite;
    }

    /** @param array<string, mixed> $general */
    private static function parsedTemporaryPath(array $general): string
    {
        $path = self::string($general, 'tmp_path');

        return $path === '' ? '/tmp' : $path;
    }

    /**
     * @param  array<string, mixed>  $database
     * @return array<string, mixed>
     */
    private static function buildDatabaseConnection(array $database, string $prefix): array
    {
        $connection = [
            'driver' => 'mysql',
            'host' => self::string($database, 'host', '127.0.0.1'),
            'port' => self::string($database, 'port', '3306'),
            'database' => self::requiredString($database, 'dbname'),
            'username' => self::string($database, 'username'),
            'password' => self::string($database, 'password'),
            'unix_socket' => self::string($database, 'unix_socket'),
            'charset' => self::string($database, 'charset', 'utf8'),
            'prefix' => $prefix,
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => self::sslOptions($database),
        ];
        $collation = self::string($database, 'collation');

        if ($collation !== '') {
            $connection['collation'] = $collation;
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $database
     * @return array<int, bool|string>
     */
    private static function sslOptions(array $database): array
    {
        if (! extension_loaded('pdo_mysql') || ! self::boolean($database, 'enable_ssl')) {
            return [];
        }

        $options = [];
        $mapping = [
            'ssl_ca' => \PDO::MYSQL_ATTR_SSL_CA,
            'ssl_cert' => \PDO::MYSQL_ATTR_SSL_CERT,
            'ssl_key' => \PDO::MYSQL_ATTR_SSL_KEY,
            'ssl_ca_path' => \PDO::MYSQL_ATTR_SSL_CAPATH,
            'ssl_cipher' => \PDO::MYSQL_ATTR_SSL_CIPHER,
        ];

        foreach ($mapping as $key => $attribute) {
            $value = self::string($database, $key);

            if ($value !== '') {
                $options[$attribute] = $value;
            }
        }

        if (self::boolean($database, 'ssl_no_verify')) {
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function requiredString(array $values, string $key): string
    {
        $value = self::string($values, $key);

        if ($value === '') {
            throw new RuntimeException("The Matomo configuration value [{$key}] is missing.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function string(array $values, string $key, string $default = ''): string
    {
        $value = $values[$key] ?? $default;

        if (! is_scalar($value)) {
            throw new RuntimeException("The Matomo configuration value [{$key}] is invalid.");
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function nullableString(array $values, string $key): ?string
    {
        $value = trim(self::string($values, $key));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function commaSeparatedList(
        array $values,
        string $key,
        array $default = [],
    ): array {
        if (! array_key_exists($key, $values)) {
            return $default;
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', self::string($values, $key))),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $general
     * @return array<string, bool>
     */
    private static function uniqueVisitorsByPeriod(array $general): array
    {
        $yearAndRange = self::boolean(
            $general,
            'enable_processing_unique_visitors_year_and_range',
        );

        return [
            'day' => self::boolean($general, 'enable_processing_unique_visitors_day', true),
            'week' => self::boolean($general, 'enable_processing_unique_visitors_week', true),
            'month' => self::boolean($general, 'enable_processing_unique_visitors_month', true),
            'year' => self::boolean($general, 'enable_processing_unique_visitors_year') || $yearAndRange,
            'range' => self::boolean($general, 'enable_processing_unique_visitors_range') || $yearAndRange,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function boolean(array $values, string $key, bool $default = false): bool
    {
        return in_array(
            strtolower(self::string($values, $key, $default ? '1' : '0')),
            ['1', 'true', 'yes', 'on'],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function nullableBoolean(array $values, string $key): ?bool
    {
        return array_key_exists($key, $values)
            ? self::boolean($values, $key)
            : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            is_string(...),
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>|null
     */
    private static function nullableStringList(array $values, string $key): ?array
    {
        return array_key_exists($key, $values)
            ? self::stringList($values, $key)
            : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function stringMap(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $name => $item) {
            if (is_string($name) && is_scalar($item)) {
                $map[$name] = (string) $item;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, string>
     */
    private static function parseTransitionsMaxPeriodAllowed(array $configuration): array
    {
        $periods = [];

        foreach ($configuration as $sectionName => $section) {
            if (! is_array($section)
                || preg_match('/^Transitions(?:_([1-9][0-9]*))?$/D', $sectionName, $matches) !== 1) {
                continue;
            }

            $period = trim(self::string($section, 'max_period_allowed'));

            if ($period !== '') {
                $periods[isset($matches[1]) ? (int) $matches[1] : 0] = $period;
            }
        }

        return $periods;
    }

    /**
     * @param  array<string, mixed>  $general
     * @return list<string>
     */
    private static function parseLoginAllowlistIps(array $general): array
    {
        $allowlist = self::stringList($general, 'login_allowlist_ip');

        return $allowlist !== []
            ? $allowlist
            : self::stringList($general, 'login_whitelist_ip');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function positiveInteger(array $values, string $key, int $default): int
    {
        $value = self::string($values, $key, (string) $default);

        return preg_match('/^[1-9]\d*$/D', $value) === 1 ? (int) $value : $default;
    }

    /** @param array<string, mixed> $values */
    private static function number(array $values, string $key, float $default): float
    {
        $value = self::string($values, $key, (string) $default);

        if (! is_numeric($value)) {
            return $default;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : $default;
    }

    /** @param array<string, mixed> $values */
    private static function nonNegativeInteger(array $values, string $key, int $default): int
    {
        $value = self::string($values, $key, (string) $default);

        return preg_match('/^\d+$/D', $value) === 1 ? (int) $value : $default;
    }

    /** @param array<string, mixed> $values */
    private static function nullablePositiveInteger(array $values, string $key): ?int
    {
        if (! array_key_exists($key, $values)) {
            return null;
        }

        $value = self::string($values, $key);

        return preg_match('/^[1-9]\d*$/D', $value) === 1 ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, int>
     */
    private static function parsePagePerformanceTimingCaps(array $values): array
    {
        $caps = [];

        foreach ([
            'time_network',
            'time_server',
            'time_transfer',
            'time_dom_processing',
            'time_dom_completion',
            'time_on_load',
        ] as $column) {
            $value = self::string($values, $column.'_cap_duration_ms', '0');
            $caps[$column] = preg_match('/^\d+$/D', $value) === 1 ? (int) $value : 0;
        }

        return $caps;
    }
}
