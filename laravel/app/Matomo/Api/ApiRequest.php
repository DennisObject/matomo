<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use App\Matomo\Api\Exceptions\ConflictingAuthenticationParameters;
use App\Matomo\Api\Exceptions\InvalidApiParameter;
use App\Matomo\Api\Exceptions\MissingApiParameter;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use Illuminate\Http\Request;

final readonly class ApiRequest
{
    private const string FLOAT_PATTERN = '/^[-+]?((([0-9]+(_[0-9]+)*)|'.
        '(([0-9]+(_[0-9]+)*)?\.([0-9]+(_[0-9]+)*))|'.
        '(([0-9]+(_[0-9]+)*)\.([0-9]+(_[0-9]+)*)?))'.
        '([eE][+-]?([0-9]+(_[0-9]+)*))?)$/D';

    /** @var list<string> */
    private const array VISITS_SUMMARY_METHODS = [
        'VisitsSummary.get',
        'VisitsSummary.getVisits',
        'VisitsSummary.getUniqueVisitors',
        'VisitsSummary.getUsers',
        'VisitsSummary.getActions',
        'VisitsSummary.getMaxActions',
        'VisitsSummary.getBounceCount',
        'VisitsSummary.getVisitsConverted',
        'VisitsSummary.getSumVisitsLength',
        'VisitsSummary.getSumVisitsLengthPretty',
    ];

    /** @var list<string> */
    private const array VISIT_TIME_METHODS = [
        'VisitTime.getByDayOfWeek',
        'VisitTime.getVisitInformationPerLocalTime',
        'VisitTime.getVisitInformationPerServerTime',
    ];

    /** @var list<string> */
    private const array VISITOR_INTEREST_METHODS = [
        'VisitorInterest.getNumberOfVisitsPerVisitDuration',
        'VisitorInterest.getNumberOfVisitsPerPage',
        'VisitorInterest.getNumberOfVisitsByDaysSinceLast',
        'VisitorInterest.getNumberOfVisitsByVisitCount',
    ];

    /** @var list<string> */
    private const array USER_LANGUAGE_METHODS = [
        'UserLanguage.getLanguage',
        'UserLanguage.getLanguageCode',
    ];

    /** @var list<string> */
    private const array RESOLUTION_METHODS = [
        'Resolution.getResolution',
        'Resolution.getConfiguration',
    ];

    private const string DEVICE_PLUGINS_METHOD = 'DevicePlugins.getPlugin';

    /** @var list<string> */
    private const array DEVICES_DETECTION_METHODS = [
        'DevicesDetection.getType',
        'DevicesDetection.getBrand',
        'DevicesDetection.getModel',
        'DevicesDetection.getOsFamilies',
        'DevicesDetection.getOsVersions',
        'DevicesDetection.getBrowsers',
        'DevicesDetection.getBrowserVersions',
        'DevicesDetection.getBrowserEngines',
    ];

    private const string PAGE_PERFORMANCE_METHOD = 'PagePerformance.get';

    private const string USER_ID_METHOD = 'UserId.getUsers';

    /** @var list<string> */
    private const array MULTI_SITES_METHODS = [
        'MultiSites.getAll',
        'MultiSites.getOne',
        'MultiSites.getAllWithGroups',
    ];

    /** @var list<string> */
    private const array ANNOTATION_METHODS = [
        'Annotations.add',
        'Annotations.save',
        'Annotations.delete',
        'Annotations.deleteAll',
        'Annotations.get',
        'Annotations.getAll',
        'Annotations.getAnnotationCountForDates',
    ];

    /** @var list<string> */
    private const array ACTIONS_METHODS = [
        'Actions.get',
        'Actions.getPageUrls',
        'Actions.getPageUrlsFollowingSiteSearch',
        'Actions.getPageTitlesFollowingSiteSearch',
        'Actions.getEntryPageUrls',
        'Actions.getExitPageUrls',
        'Actions.getPageUrl',
        'Actions.getPageTitles',
        'Actions.getEntryPageTitles',
        'Actions.getExitPageTitles',
        'Actions.getPageTitle',
        'Actions.getDownloads',
        'Actions.getDownload',
        'Actions.getOutlinks',
        'Actions.getOutlink',
        'Actions.getSiteSearchKeywords',
        'Actions.getSiteSearchNoResultKeywords',
        'Actions.getSiteSearchCategories',
    ];

    /** @var list<string> */
    private const array ACTIONS_SUBTABLE_METHODS = [
        'Actions.getPageUrls',
        'Actions.getPageUrlsFollowingSiteSearch',
        'Actions.getPageTitlesFollowingSiteSearch',
        'Actions.getEntryPageUrls',
        'Actions.getExitPageUrls',
        'Actions.getPageTitles',
        'Actions.getEntryPageTitles',
        'Actions.getExitPageTitles',
        'Actions.getDownloads',
        'Actions.getOutlinks',
    ];

    /** @var list<string> */
    private const array EVENTS_METHODS = [
        'Events.getCategory',
        'Events.getAction',
        'Events.getName',
        'Events.getActionFromCategoryId',
        'Events.getNameFromCategoryId',
        'Events.getCategoryFromActionId',
        'Events.getNameFromActionId',
        'Events.getActionFromNameId',
        'Events.getCategoryFromNameId',
    ];

    /** @var list<string> */
    private const array EVENTS_SUBTABLE_METHODS = [
        'Events.getActionFromCategoryId',
        'Events.getNameFromCategoryId',
        'Events.getCategoryFromActionId',
        'Events.getNameFromActionId',
        'Events.getActionFromNameId',
        'Events.getCategoryFromNameId',
    ];

    /** @var list<string> */
    private const array CONTENTS_METHODS = [
        'Contents.getContentNames',
        'Contents.getContentPieces',
    ];

    /** @var list<string> */
    private const array BOT_TRACKING_ARCHIVE_METHODS = [
        'BotTracking.get',
        'BotTracking.getAIChatbotRequests',
        'BotTracking.getPageUrlsForAIChatbot',
        'BotTracking.getDocumentUrlsForAIChatbot',
        'BotTracking.getAIChatbotContentPages',
        'BotTracking.getAIChatbotContentDocuments',
        'BotTracking.getAIChatbotBrokenContent',
        'BotTracking.getAIChatbotHumanFavouredPages',
        'BotTracking.getAIChatbotAIFavouredPages',
    ];

    /** @var list<string> */
    private const array BOT_TRACKING_SUBTABLE_METHODS = [
        'BotTracking.getPageUrlsForAIChatbot',
        'BotTracking.getDocumentUrlsForAIChatbot',
    ];

    /** @var list<string> */
    private const array BOT_TRACKING_REALTIME_METHODS = [
        'BotTracking.getAIChatbotsRealTime',
        'BotTracking.getTopPageUrlsRealTime',
    ];

    private const string CUSTOM_JS_TRACKER_METHOD = 'CustomJsTracker.doesIncludePluginTrackersAutomatically';

    private const string PROFESSIONAL_SERVICES_METHOD = 'ProfessionalServices.dismissWidget';

    private const string LOGIN_METHOD = 'Login.unblockBruteForceIPs';

    private const string AI_AGENTS_METHOD = 'AIAgents.get';

    /** @var list<string> */
    private const array CUSTOM_DIMENSIONS_METHODS = [
        'CustomDimensions.getCustomDimension',
        'CustomDimensions.configureNewCustomDimension',
        'CustomDimensions.configureExistingCustomDimension',
        'CustomDimensions.getConfiguredCustomDimensions',
        'CustomDimensions.getConfiguredCustomDimensionsHavingScope',
        'CustomDimensions.getAvailableScopes',
        'CustomDimensions.getAvailableExtractionDimensions',
    ];

    /** @var list<string> */
    private const array DB_STATS_METHODS = [
        'DBStats.getGeneralInformation',
        'DBStats.getDBStatus',
        'DBStats.getDatabaseUsageSummary',
        'DBStats.getTrackerDataSummary',
        'DBStats.getMetricDataSummary',
        'DBStats.getMetricDataSummaryByYear',
        'DBStats.getReportDataSummary',
        'DBStats.getReportDataSummaryByYear',
        'DBStats.getAdminDataSummary',
        'DBStats.getIndividualReportsSummary',
        'DBStats.getIndividualMetricsSummary',
    ];

    /** @var list<string> */
    private const array EXAMPLE_API_METHODS = [
        'ExampleAPI.getMatomoVersion',
        'ExampleAPI.getAnswerToLife',
        'ExampleAPI.getObject',
        'ExampleAPI.getSum',
        'ExampleAPI.getNull',
        'ExampleAPI.getDescriptionArray',
        'ExampleAPI.getCompetitionDatatable',
        'ExampleAPI.getMoreInformationAnswerToLife',
        'ExampleAPI.getMultiArray',
    ];

    /** @var list<string> */
    private const array EXAMPLE_PLUGIN_METHODS = [
        'ExamplePlugin.getAnswerToLife',
        'ExamplePlugin.getExampleReport',
        'ExamplePlugin.getExampleArchivedMetric',
        'ExamplePlugin.getSegmentHash',
    ];

    /** @var list<string> */
    private const array EXAMPLE_PLUGIN_REPORT_METHODS = [
        'ExamplePlugin.getExampleReport',
        'ExamplePlugin.getExampleArchivedMetric',
    ];

    private const string EXAMPLE_REPORT_METHOD = 'ExampleReport.getExampleReport';

    /** @var list<string> */
    private const array FEEDBACK_METHODS = [
        'Feedback.sendFeedbackForFeature',
        'Feedback.sendFeedbackForSurvey',
        'Feedback.updateFeedbackReminderDate',
    ];

    /** @var list<string> */
    private const array GOALS_MANAGEMENT_METHODS = [
        'Goals.getGoal',
        'Goals.getGoals',
        'Goals.addGoal',
        'Goals.updateGoal',
        'Goals.deleteGoal',
    ];

    /** @var list<string> */
    private const array GOALS_REPORT_METHODS = [
        'Goals.getItemsSku',
        'Goals.getItemsName',
        'Goals.getItemsCategory',
        'Goals.get',
        'Goals.getMetrics',
        'Goals.getDaysToConversion',
        'Goals.getVisitsUntilConversion',
    ];

    /** @var list<string> */
    private const array JS_TRACKER_INSTALL_CHECK_METHODS = [
        'JsTrackerInstallCheck.wasJsTrackerInstallTestSuccessful',
        'JsTrackerInstallCheck.initiateJsTrackerInstallTest',
    ];

    /** @var list<string> */
    private const array LANGUAGES_MANAGER_METHODS = [
        'LanguagesManager.isLanguageAvailable',
        'LanguagesManager.getAvailableLanguages',
        'LanguagesManager.getAvailableLanguagesInfo',
        'LanguagesManager.getAvailableLanguageNames',
        'LanguagesManager.getTranslationsForLanguage',
        'LanguagesManager.getLanguageForUser',
        'LanguagesManager.setLanguageForUser',
        'LanguagesManager.uses12HourClockForUser',
        'LanguagesManager.set12HourClockForUser',
    ];

    /** @var list<string> */
    private const array OVERLAY_METHODS = [
        'Overlay.getTranslations',
        'Overlay.getFollowingPages',
    ];

    /** @var list<string> */
    private const array TRANSITIONS_METHODS = [
        'Transitions.getTransitionsForPageTitle',
        'Transitions.getTransitionsForPageUrl',
        'Transitions.getTransitionsForAction',
        'Transitions.getTranslations',
        'Transitions.isPeriodAllowed',
    ];

    /** @var list<string> */
    private const array EXAMPLE_UI_METHODS = [
        'ExampleUI.getTemperaturesEvolution',
        'ExampleUI.getTemperatures',
        'ExampleUI.getPlanetRatios',
        'ExampleUI.getPlanetRatiosWithLogos',
    ];

    /** @var list<string> */
    private const array DASHBOARD_METHODS = [
        'Dashboard.getDashboards',
        'Dashboard.createNewDashboardForUser',
        'Dashboard.removeDashboard',
        'Dashboard.copyDashboardToUser',
        'Dashboard.resetDashboardLayout',
    ];

    /** @var list<string> */
    private const array CORE_ADMIN_HOME_METHODS = [
        'CoreAdminHome.archiveReports',
        'CoreAdminHome.deleteAllTrackingFailures',
        'CoreAdminHome.deleteTrackingFailure',
        'CoreAdminHome.getOptOutJSEmbedCode',
        'CoreAdminHome.getOptOutSelfContainedEmbedCode',
        'CoreAdminHome.getTrackingFailures',
        'CoreAdminHome.invalidateArchivedReports',
        'CoreAdminHome.runCronArchiving',
        'CoreAdminHome.runScheduledTasks',
        'CoreAdminHome.setArchiveSettings',
        'CoreAdminHome.setBrandingSettings',
        'CoreAdminHome.setTrustedHosts',
        'CoreAdminHome.whatIsNewMarkAllChangesReadForCurrentUser',
    ];

    /** @var list<string> */
    private const array AI_PROVIDERS_METHODS = [
        'AIProviders.getSettings',
        'AIProviders.saveSettings',
        'AIProviders.testConnection',
        'AIProviders.disconnectProvider',
    ];

    /** @var list<string> */
    private const array USER_COUNTRY_METHODS = [
        'UserCountry.getCountry',
        'UserCountry.getContinent',
        'UserCountry.getRegion',
        'UserCountry.getCity',
        'UserCountry.getCountryCodeMapping',
        'UserCountry.getNumberOfDistinctCountries',
        'UserCountry.getLocationFromIP',
        'UserCountry.setLocationProvider',
    ];

    private function __construct(
        public string $module,
        public string $method,
        public string $format,
        public ?string $callback,
        public bool $serialize,
        public bool $convertToUnicode,
        public bool $showMetadata,
        public ?string $restrictSitesToLogin,
        public ?int $idSite,
        /** @var list<string>|null */
        public ?array $timezones,
        public ?string $ipRange,
        public ?string $pluginName,
        public ?string $siteUrl,
        public ?string $timezone,
        public ?string $widgetName,
        public ?string $tourChallengeId,
        public ?string $countryCode,
        public ?bool $multipleTimezonesInCountry,
        public ?string $siteGroup,
        public bool $fetchAliasUrls,
        public ?string $sitePattern,
        public ?int $siteLimit,
        public ?int $siteContentTimeout,
        /** @var list<int> */
        public array $sitesToExclude,
        public ?SiteAccessRole $minimumSiteAccessRole,
        /** @var list<string> */
        public array $siteTypesToExclude,
        public ?VisitsSummaryRequest $visitsSummary,
        public ?ActionsRequest $actions,
        public ?AnnotationRequest $annotations,
        public ?MultiSitesRequest $multiSites,
        public ?TwoFactorAuthRequest $twoFactorAuth,
        public ?string $locationIp,
        public ?string $locationProviderId,
        public ?AiProviderRequest $aiProvider,
        public ?DashboardRequest $dashboard,
        public ?CoreAdminHomeRequest $coreAdminHome,
        public ?ExampleApiRequest $exampleApi,
        public ?ExamplePluginRequest $examplePlugin,
        public ?ExampleUiRequest $exampleUi,
        public ?FeedbackRequest $feedback,
        public ?GoalsRequest $goals,
        public ?GoalsReportRequest $goalsReport,
        public ?JsTrackerInstallCheckRequest $jsTrackerInstallCheck,
        public ?LanguagesManagerRequest $languagesManager,
        public ?OverlayRequest $overlay,
        public ?TransitionsRequest $transitions,
        public ?BotTrackingRealtimeRequest $botTrackingRealtime,
        public ?CustomDimensionsRequest $customDimensions,
        public bool $forceCache,
        public ApiAuthentication $authentication,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::make($request, self::authentication($request));
    }

    public static function withoutAuthentication(Request $request): self
    {
        return new self(
            module: '',
            method: '',
            format: strtolower(self::safeStringInput($request, 'format', 'xml')),
            callback: self::safeNullableStringInput($request, 'callback')
                ?? self::safeNullableStringInput($request, 'jsoncallback'),
            serialize: self::booleanInput($request, 'serialize', false),
            convertToUnicode: self::booleanInput($request, 'convertToUnicode', true),
            showMetadata: self::booleanInput($request, 'showMetadata', true),
            restrictSitesToLogin: self::safeNullableStringInput($request, '_restrictSitesToLogin'),
            idSite: null,
            timezones: null,
            ipRange: null,
            pluginName: null,
            siteUrl: null,
            timezone: null,
            widgetName: null,
            tourChallengeId: null,
            countryCode: null,
            multipleTimezonesInCountry: null,
            siteGroup: null,
            fetchAliasUrls: false,
            sitePattern: null,
            siteLimit: null,
            siteContentTimeout: null,
            sitesToExclude: [],
            minimumSiteAccessRole: null,
            siteTypesToExclude: [],
            visitsSummary: null,
            actions: null,
            annotations: null,
            multiSites: null,
            twoFactorAuth: null,
            locationIp: null,
            locationProviderId: null,
            aiProvider: null,
            dashboard: null,
            coreAdminHome: null,
            exampleApi: null,
            examplePlugin: null,
            exampleUi: null,
            feedback: null,
            goals: null,
            goalsReport: null,
            jsTrackerInstallCheck: null,
            languagesManager: null,
            overlay: null,
            transitions: null,
            botTrackingRealtime: null,
            customDimensions: null,
            forceCache: false,
            authentication: new ApiAuthentication(null, false, false, null),
        );
    }

    public function isVersionRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, ['API.getMatomoVersion', 'API.getPiwikVersion'], true);
    }

    public function isPhpVersionRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'API.getPhpVersion';
    }

    public function isClientIpRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'API.getIpFromHeader';
    }

    public function isComparisonPagesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'API.getPagesComparisonsDisabledFor';
    }

    public function siteAccessRole(): ?SiteAccessRole
    {
        if ($this->module !== 'API') {
            return null;
        }

        return match ($this->method) {
            'SitesManager.getSitesIdWithViewAccess' => SiteAccessRole::View,
            'SitesManager.getSitesIdWithWriteAccess' => SiteAccessRole::Write,
            'SitesManager.getSitesIdWithAdminAccess' => SiteAccessRole::Admin,
            default => null,
        };
    }

    public function isAllSiteIdsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getAllSitesId';
    }

    public function isAllSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getAllSites';
    }

    public function isViewableSiteIdsRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getSitesIdWithAtLeastViewAccess';
    }

    public function isSiteGroupsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesGroups';
    }

    public function isSitesFromGroupRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesFromGroup';
    }

    public function isAdminSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithAdminAccess';
    }

    public function isMinimumAccessSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithMinimumAccess';
    }

    public function isViewSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithViewAccess';
    }

    public function isAtLeastViewSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesWithAtLeastViewAccess';
    }

    public function isSiteRemovalWarningsRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getMessagesToWarnOnSiteRemoval';
    }

    public function isPatternMatchSitesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getPatternMatchSites';
    }

    public function isConsentManagerDetectionRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.detectConsentManager';
    }

    public function isDefaultCurrencyRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getDefaultCurrency';
    }

    public function isCurrencySymbolsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getCurrencySymbols';
    }

    public function isCurrencyListRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getCurrencyList';
    }

    public function isDefaultTimezoneRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getDefaultTimezone';
    }

    public function isTimezoneNameRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getTimezoneName';
    }

    public function isTimezoneListRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getTimezonesList';
    }

    public function isTimezoneSupportRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.isTimezoneSupportEnabled';
    }

    public function isWebsitesCountToDisplayRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getNumWebsitesToDisplayPerPage';
    }

    public function isSiteUrlsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSiteUrlsFromId';
    }

    public function isSiteDetailsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSiteFromId';
    }

    public function isExcludedReferrersRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getExcludedReferrers';
    }

    public function isExcludedQueryParametersRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getExcludedQueryParameters';
    }

    public function isGlobalExcludedQueryParametersRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getExcludedQueryParametersGlobal';
    }

    public function isQueryParameterExclusionTypeRequest(): bool
    {
        return $this->module === 'API'
            && $this->method === 'SitesManager.getExclusionTypeForQueryParams';
    }

    public function isUniqueSiteTimezonesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getUniqueSiteTimezones';
    }

    public function isSiteIdsFromTimezonesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesIdFromTimezones';
    }

    public function isIpRangeRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getIpsForRange';
    }

    public function isPluginActivatedRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'API.isPluginActivated';
    }

    public function isSiteIdFromUrlRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'SitesManager.getSitesIdFromSiteUrl';
    }

    public function isVisitsSummaryRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::VISITS_SUMMARY_METHODS, true);
    }

    public function isVisitFrequencyRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'VisitFrequency.get';
    }

    public function isVisitTimeRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::VISIT_TIME_METHODS, true);
    }

    public function isVisitorInterestRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::VISITOR_INTEREST_METHODS, true);
    }

    public function isUserLanguageRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::USER_LANGUAGE_METHODS, true);
    }

    public function isResolutionRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::RESOLUTION_METHODS, true);
    }

    public function isDevicePluginsRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::DEVICE_PLUGINS_METHOD;
    }

    public function isDevicesDetectionRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::DEVICES_DETECTION_METHODS, true);
    }

    public function isPagePerformanceRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::PAGE_PERFORMANCE_METHOD;
    }

    public function isUserIdRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::USER_ID_METHOD;
    }

    public function isEventsRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::EVENTS_METHODS, true);
    }

    public function isActionsRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::ACTIONS_METHODS, true);
    }

    public function isAnnotationsRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::ANNOTATION_METHODS, true);
    }

    public function isMultiSitesRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::MULTI_SITES_METHODS, true);
    }

    public function isContentsRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::CONTENTS_METHODS, true);
    }

    public function isBotTrackingArchiveRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::BOT_TRACKING_ARCHIVE_METHODS, true);
    }

    public function isBotTrackingRealtimeRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::BOT_TRACKING_REALTIME_METHODS, true);
    }

    public function isCustomJsTrackerRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::CUSTOM_JS_TRACKER_METHOD;
    }

    public function isProfessionalServicesRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::PROFESSIONAL_SERVICES_METHOD;
    }

    public function isLoginRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::LOGIN_METHOD;
    }

    public function isAiAgentsRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::AI_AGENTS_METHOD;
    }

    public function isDbStatsRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::DB_STATS_METHODS, true);
    }

    public function isCustomDimensionsRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::CUSTOM_DIMENSIONS_METHODS, true);
    }

    public function isExampleApiRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::EXAMPLE_API_METHODS, true);
    }

    public function isExamplePluginRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::EXAMPLE_PLUGIN_METHODS, true);
    }

    public function isExampleReportRequest(): bool
    {
        return $this->module === 'API' && $this->method === self::EXAMPLE_REPORT_METHOD;
    }

    public function isExampleUiRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::EXAMPLE_UI_METHODS, true);
    }

    public function isFeedbackRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::FEEDBACK_METHODS, true);
    }

    public function isGoalsManagementRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::GOALS_MANAGEMENT_METHODS, true);
    }

    public function isGoalsReportRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::GOALS_REPORT_METHODS, true);
    }

    public function isJsTrackerInstallCheckRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::JS_TRACKER_INSTALL_CHECK_METHODS, true);
    }

    public function isLanguagesManagerRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::LANGUAGES_MANAGER_METHODS, true);
    }

    public function isOverlayRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::OVERLAY_METHODS, true);
    }

    public function isOverlayTranslationsRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'Overlay.getTranslations';
    }

    public function isOverlayFollowingPagesRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'Overlay.getFollowingPages';
    }

    public function isTransitionsRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::TRANSITIONS_METHODS, true);
    }

    public function isAiProvidersRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::AI_PROVIDERS_METHODS, true);
    }

    public function isDashboardRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::DASHBOARD_METHODS, true);
    }

    public function isCoreAdminHomeRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, self::CORE_ADMIN_HOME_METHODS, true);
    }

    public function isTourRequest(): bool
    {
        return $this->module === 'API'
            && in_array($this->method, ['Tour.getChallenges', 'Tour.getLevel', 'Tour.skipChallenge'], true);
    }

    public function isTwoFactorAuthRequest(): bool
    {
        return $this->module === 'API' && $this->method === 'TwoFactorAuth.resetTwoFactorAuth';
    }

    public function isUserCountryRequest(): bool
    {
        return $this->module === 'API' && in_array($this->method, self::USER_COUNTRY_METHODS, true);
    }

    public function hasSupportedFormat(): bool
    {
        return in_array(
            $this->format,
            ['console', 'csv', 'html', 'json', 'original', 'rss', 'tsv', 'xml'],
            true,
        );
    }

    private static function make(Request $request, ApiAuthentication $authentication): self
    {
        $module = self::stringInput($request, 'module');
        $method = self::stringInput($request, 'method');

        return new self(
            module: $module,
            method: $method,
            format: strtolower(self::stringInput($request, 'format', 'xml')),
            callback: self::nullableStringInput($request, 'callback')
                ?? self::nullableStringInput($request, 'jsoncallback'),
            serialize: self::booleanInput($request, 'serialize', false),
            convertToUnicode: self::booleanInput($request, 'convertToUnicode', true),
            showMetadata: self::booleanInput($request, 'showMetadata', true),
            restrictSitesToLogin: self::nullableStringInput($request, '_restrictSitesToLogin'),
            idSite: self::siteId($request, $module, $method),
            timezones: self::timezones($request, $module, $method),
            ipRange: self::ipRange($request, $module, $method),
            pluginName: self::pluginName($request, $module, $method),
            siteUrl: self::siteUrl($request, $module, $method),
            timezone: self::timezone($request, $module, $method),
            widgetName: self::widgetName($request, $module, $method),
            tourChallengeId: self::tourChallengeId($request, $module, $method),
            countryCode: self::timezoneCountryCode($request, $module, $method),
            multipleTimezonesInCountry: self::multipleTimezonesInCountry($request, $module, $method),
            siteGroup: self::siteGroup($request, $module, $method),
            fetchAliasUrls: self::fetchAliasUrls($request, $module, $method),
            sitePattern: self::sitePattern($request, $module, $method),
            siteLimit: self::siteLimit($request, $module, $method),
            siteContentTimeout: self::siteContentTimeout($request, $module, $method),
            sitesToExclude: self::sitesToExclude($request, $module, $method),
            minimumSiteAccessRole: self::minimumSiteAccessRole($request, $module, $method),
            siteTypesToExclude: self::siteTypesToExclude($request, $module, $method),
            visitsSummary: self::visitsSummary($request, $module, $method),
            actions: self::actions($request, $module, $method),
            annotations: self::annotations($request, $module, $method),
            multiSites: self::multiSites($request, $module, $method),
            twoFactorAuth: self::twoFactorAuth($request, $module, $method),
            locationIp: self::locationIp($request, $module, $method),
            locationProviderId: self::locationProviderId($request, $module, $method),
            aiProvider: self::aiProvider($request, $module, $method),
            dashboard: self::dashboard($request, $module, $method),
            coreAdminHome: self::coreAdminHome($request, $module, $method),
            exampleApi: self::exampleApi($request, $module, $method),
            examplePlugin: self::examplePlugin($request, $module, $method),
            exampleUi: self::exampleUi($request, $module, $method),
            feedback: self::feedback($request, $module, $method),
            goals: self::goals($request, $module, $method),
            goalsReport: self::goalsReport($request, $module, $method),
            jsTrackerInstallCheck: self::jsTrackerInstallCheck($request, $module, $method),
            languagesManager: self::languagesManager($request, $module, $method),
            overlay: self::overlay($request, $module, $method),
            transitions: self::transitions($request, $module, $method),
            botTrackingRealtime: self::botTrackingRealtime($request, $module, $method),
            customDimensions: self::customDimensions($request, $module, $method),
            forceCache: self::booleanInput($request, 'forceCache', false),
            authentication: $authentication,
        );
    }

    private static function customDimensions(
        Request $request,
        string $module,
        string $method,
    ): ?CustomDimensionsRequest {
        if ($module !== 'API' || ! in_array($method, self::CUSTOM_DIMENSIONS_METHODS, true)) {
            return null;
        }

        $needsSite = $method !== 'CustomDimensions.getAvailableExtractionDimensions';
        $needsDimension = in_array($method, [
            'CustomDimensions.getCustomDimension',
            'CustomDimensions.configureExistingCustomDimension',
        ], true);
        $siteId = $needsSite ? self::requiredInteger($request, 'idSite') : null;
        $dimensionId = $needsDimension ? self::requiredInteger($request, 'idDimension') : null;

        if ($siteId !== null && $siteId < 1) {
            throw new InvalidApiParameter('idSite');
        }

        if ($dimensionId !== null && $dimensionId < 1) {
            throw new InvalidApiParameter('idDimension');
        }

        $scope = $method === 'CustomDimensions.getConfiguredCustomDimensionsHavingScope'
            ? strtolower(self::requiredString($request, 'scope'))
            : null;

        if ($scope !== null && ! in_array($scope, ['visit', 'action', 'conversion'], true)) {
            throw new InvalidApiParameter('scope');
        }

        return new CustomDimensionsRequest($siteId, $dimensionId, $scope);
    }

    private static function botTrackingRealtime(
        Request $request,
        string $module,
        string $method,
    ): ?BotTrackingRealtimeRequest {
        if ($module !== 'API' || ! in_array($method, self::BOT_TRACKING_REALTIME_METHODS, true)) {
            return null;
        }

        [$siteIds, $allSites] = self::reportSiteIds($request);
        $lastMinutes = self::inputValue($request, 'lastMinutes');

        if ($lastMinutes === null || $lastMinutes === '') {
            $lastMinutes = 30;
        }

        if ((! is_int($lastMinutes) && (! is_string($lastMinutes) || ! ctype_digit($lastMinutes)))
            || (int) $lastMinutes < 1
            || (int) $lastMinutes > 720) {
            throw new InvalidApiParameter(
                'lastMinutes',
                'lastMinutes only accepts values between 1 and 720',
            );
        }

        return new BotTrackingRealtimeRequest($siteIds, $allSites, (int) $lastMinutes);
    }

    private static function coreAdminHome(
        Request $request,
        string $module,
        string $method,
    ): ?CoreAdminHomeRequest {
        if ($module !== 'API' || ! in_array($method, self::CORE_ADMIN_HOME_METHODS, true)) {
            return null;
        }

        if ($method !== 'CoreAdminHome.deleteTrackingFailure') {
            if ($method === 'CoreAdminHome.archiveReports') {
                $siteId = self::requiredInteger($request, 'idSite');
                $period = strtolower(self::requiredString($request, 'period'));
                $date = self::requiredString($request, 'date');

                if (! in_array($period, ['day', 'week', 'month', 'year', 'range'], true)) {
                    throw new InvalidApiParameter('period', "The period '{$period}' is not supported.");
                }

                $reportInput = self::inputValue($request, 'report');
                $reports = in_array($reportInput, [null, '', false, 0, '0', 'false'], true)
                    ? []
                    : self::stringList($reportInput, 'report', splitCommaSeparated: false);
                $segment = self::nullableStringInput($request, 'segment');
                $segment = in_array(strtolower($segment ?? ''), ['', '0', 'false'], true)
                    ? null
                    : substr($segment ?? '', 0, 8193);
                $plugin = self::nullableStringInput($request, 'plugin');
                $plugin = in_array(strtolower($plugin ?? ''), ['', '0', 'false'], true)
                    ? null
                    : $plugin;

                return new CoreAdminHomeRequest(
                    siteId: null,
                    failureId: null,
                    archiveReport: new ArchiveReportRequest(
                        siteId: $siteId,
                        period: $period,
                        date: $date,
                        segment: $segment,
                        plugin: $plugin,
                        reports: $reports,
                        force: true,
                    ),
                );
            }

            if ($method === 'CoreAdminHome.setArchiveSettings') {
                $browserTrigger = self::requiredBoolean(
                    $request,
                    'enableBrowserTriggerArchiving',
                );
                $timeToLive = self::inputValue($request, 'todayArchiveTimeToLive');

                if ($timeToLive === null) {
                    throw new MissingApiParameter('todayArchiveTimeToLive');
                }

                if (! is_scalar($timeToLive)) {
                    throw new InvalidApiParameter('todayArchiveTimeToLive');
                }

                return new CoreAdminHomeRequest(
                    siteId: null,
                    failureId: null,
                    browserTriggerArchivingEnabled: $browserTrigger,
                    todayArchiveTimeToLive: (int) $timeToLive,
                );
            }

            if ($method === 'CoreAdminHome.setBrandingSettings') {
                return new CoreAdminHomeRequest(
                    siteId: null,
                    failureId: null,
                    useCustomLogo: self::requiredBoolean($request, 'useCustomLogo'),
                    hasCustomLogo: self::requiredBoolean($request, 'hasCustomLogo'),
                    hasCustomFavicon: self::requiredBoolean($request, 'hasCustomFavicon'),
                );
            }

            if (in_array($method, [
                'CoreAdminHome.getOptOutJSEmbedCode',
                'CoreAdminHome.getOptOutSelfContainedEmbedCode',
            ], true)) {
                $backgroundColor = self::requiredString($request, 'backgroundColor');
                $fontColor = self::requiredString($request, 'fontColor');
                $fontSize = self::requiredString($request, 'fontSize');
                $fontFamily = self::requiredString($request, 'fontFamily');
                $javascript = $method === 'CoreAdminHome.getOptOutJSEmbedCode';

                return new CoreAdminHomeRequest(
                    siteId: null,
                    failureId: null,
                    optOutEmbed: new OptOutEmbedRequest(
                        backgroundColor: $backgroundColor,
                        fontColor: $fontColor,
                        fontSize: $fontSize,
                        fontFamily: $fontFamily,
                        applyStyling: $javascript
                            ? self::requiredBoolean($request, 'applyStyling')
                            : self::booleanInput($request, 'applyStyling', false),
                        showIntro: $javascript
                            ? self::requiredBoolean($request, 'showIntro')
                            : self::booleanInput($request, 'showIntro', true),
                        matomoUrl: $javascript ? self::requiredString($request, 'matomoUrl') : null,
                        language: $javascript ? self::requiredString($request, 'language') : null,
                        cookiePath: $javascript ? '' : self::stringInput($request, 'cookiePath'),
                        cookieDomain: $javascript ? '' : self::stringInput($request, 'cookieDomain'),
                        cookieSameSite: $javascript
                            ? 'Lax'
                            : self::stringInput($request, 'cookieSameSite', 'Lax'),
                    ),
                );
            }

            if ($method === 'CoreAdminHome.invalidateArchivedReports') {
                $siteIds = self::requiredStringList($request, 'idSites');
                $dateInput = self::inputValue($request, 'dates');

                if ($dateInput === null) {
                    throw new MissingApiParameter('dates');
                }

                $allSites = count($siteIds) === 1 && strtolower($siteIds[0]) === 'all';
                $parsedSiteIds = [];

                if (! $allSites) {
                    foreach ($siteIds as $siteId) {
                        if ($siteId === '') {
                            continue;
                        }

                        if (! is_numeric($siteId)
                            || (string) (int) $siteId !== $siteId
                            || (int) $siteId <= 0) {
                            throw new InvalidApiParameter(
                                'idSites',
                                "The parameter 'idSite=' contains an invalid value.",
                            );
                        }

                        $parsedSiteIds[] = (int) $siteId;
                    }
                }

                $period = self::nullableStringInput($request, 'period');
                $period = in_array(strtolower($period ?? ''), ['', '0', 'false'], true)
                    ? null
                    : strtolower($period ?? '');

                if ($period !== null && ! in_array($period, ['day', 'week', 'month', 'year', 'range'], true)) {
                    throw new InvalidApiParameter('period', "The period '{$period}' is not supported.");
                }

                $dates = self::stringList(
                    $dateInput,
                    'dates',
                    splitCommaSeparated: $period !== 'range',
                );

                $segment = self::nullableStringInput($request, 'segment');
                $segment = trim($segment ?? '');
                $segment = in_array(strtolower($segment), ['', '0', 'false'], true)
                    ? null
                    : substr($segment, 0, 8192);

                return new CoreAdminHomeRequest(
                    siteId: null,
                    failureId: null,
                    archiveInvalidation: new ArchiveInvalidationRequest(
                        siteIds: array_values(array_unique($parsedSiteIds)),
                        allSites: $allSites,
                        dates: $dates,
                        period: $period,
                        segment: $segment,
                        cascadeDown: self::booleanInput($request, 'cascadeDown', false),
                        forceInvalidateNonexistent: self::booleanInput(
                            $request,
                            '_forceInvalidateNonexistent',
                            false,
                        ),
                    ),
                );
            }

            if ($method === 'CoreAdminHome.setTrustedHosts') {
                $trustedHosts = self::inputValue($request, 'trustedHosts');

                if ($trustedHosts === null) {
                    throw new MissingApiParameter('trustedHosts');
                }

                $values = is_array($trustedHosts)
                    ? $trustedHosts
                    : explode(',', (string) $trustedHosts);
                $hosts = [];

                foreach ($values as $host) {
                    if (! is_scalar($host)) {
                        throw new InvalidApiParameter('trustedHosts');
                    }

                    $hosts[] = str_replace("\0", '', (string) $host);
                }

                return new CoreAdminHomeRequest(null, null, trustedHosts: $hosts);
            }

            return new CoreAdminHomeRequest(null, null);
        }

        $siteId = self::requiredInteger($request, 'idSite');
        $failureId = self::inputValue($request, 'idFailure');

        if ($failureId === null || $failureId === '' || ! is_scalar($failureId)) {
            throw new MissingApiParameter('idFailure');
        }

        return new CoreAdminHomeRequest(
            siteId: $siteId,
            failureId: is_int($failureId) ? $failureId : (string) $failureId,
        );
    }

    private static function languagesManager(
        Request $request,
        string $module,
        string $method,
    ): ?LanguagesManagerRequest {
        if ($module !== 'API' || ! in_array($method, self::LANGUAGES_MANAGER_METHODS, true)) {
            return null;
        }

        $languageCode = '';
        $login = '';

        if (in_array($method, [
            'LanguagesManager.isLanguageAvailable',
            'LanguagesManager.getTranslationsForLanguage',
            'LanguagesManager.setLanguageForUser',
        ], true)) {
            $languageCode = self::nullableStringInput($request, 'languageCode')
                ?? throw new MissingApiParameter('languageCode');
        }

        if (in_array($method, [
            'LanguagesManager.getLanguageForUser',
            'LanguagesManager.setLanguageForUser',
            'LanguagesManager.uses12HourClockForUser',
            'LanguagesManager.set12HourClockForUser',
        ], true)) {
            $login = self::nullableStringInput($request, 'login')
                ?? throw new MissingApiParameter('login');
        }

        $use12HourClock = false;

        if ($method === 'LanguagesManager.set12HourClockForUser') {
            if (self::inputValue($request, 'use12HourClock') === null) {
                throw new MissingApiParameter('use12HourClock');
            }

            $use12HourClock = self::booleanFromArray($request->query->all(), 'use12HourClock')
                ?? self::booleanFromArray($request->request->all(), 'use12HourClock')
                ?? false;
        }

        return new LanguagesManagerRequest(
            languageCode: $languageCode,
            login: $login,
            ignoreConfig: self::booleanInput($request, '_ignoreConfig', false),
            excludeNonCorePlugins: self::booleanInput($request, 'excludeNonCorePlugins', true),
            use12HourClock: $use12HourClock,
        );
    }

    private static function overlay(Request $request, string $module, string $method): ?OverlayRequest
    {
        if ($module !== 'API' || $method !== 'Overlay.getFollowingPages') {
            return null;
        }

        [$siteId, $period, $date] = self::reportContext($request);
        $url = self::nullableStringInput($request, 'url');

        if ($url === null) {
            throw new MissingApiParameter('url');
        }

        $segment = trim(self::nullableStringInput($request, 'segment') ?? '');

        return new OverlayRequest(
            siteId: $siteId,
            period: $period,
            date: $date,
            url: $url,
            segment: $segment === '' ? null : substr($segment, 0, 8192),
            filterLimit: self::integerInput($request, 'filter_limit', 100, -1),
            filterOffset: self::integerInput($request, 'filter_offset', 0, 0),
        );
    }

    private static function transitions(Request $request, string $module, string $method): ?TransitionsRequest
    {
        if ($module !== 'API' || ! in_array($method, self::TRANSITIONS_METHODS, true)) {
            return null;
        }

        if ($method === 'Transitions.getTranslations') {
            return null;
        }

        [$siteId, $period, $date] = self::reportContext($request);

        if ($method === 'Transitions.isPeriodAllowed') {
            return new TransitionsRequest(
                siteId: $siteId,
                period: $period,
                date: $date,
            );
        }

        [$actionParameter, $actionType] = match ($method) {
            'Transitions.getTransitionsForPageTitle' => ['pageTitle', 'title'],
            'Transitions.getTransitionsForPageUrl' => ['pageUrl', 'url'],
            default => ['actionName', strtolower(self::stringInput($request, 'actionType'))],
        };
        $actionName = self::nullableStringInput($request, $actionParameter);

        if ($actionName === null) {
            throw new MissingApiParameter($actionParameter);
        }

        if (! in_array($actionType, ['url', 'title'], true)) {
            throw new InvalidApiParameter('actionType', 'Unknown action type');
        }

        $limit = self::inputValue($request, 'limitBeforeGrouping');

        if (in_array($limit, [null, '', false], true)) {
            $limitBeforeGrouping = 0;
        } elseif (! is_scalar($limit) || ! is_numeric($limit)) {
            throw new InvalidApiParameter(
                'limitBeforeGrouping',
                'limitBeforeGrouping has to be an integer.',
            );
        } else {
            $limitBeforeGrouping = (int) $limit;
        }

        $partsValue = $method === 'Transitions.getTransitionsForAction'
            ? self::stringInput($request, 'parts', 'all')
            : 'all';
        $allParts = $partsValue === 'all';
        $parts = [];

        if (! $allParts) {
            foreach (explode(',', $partsValue) as $part) {
                if (in_array($part, [
                    'externalReferrers',
                    'followingActions',
                    'internalReferrers',
                ], true)) {
                    $parts[] = $part;
                }
            }

            $parts = array_values(array_unique($parts));
        }

        $segment = trim(self::nullableStringInput($request, 'segment') ?? '');

        return new TransitionsRequest(
            siteId: $siteId,
            period: $period,
            date: $date,
            actionName: $actionName,
            actionType: $actionType,
            segment: $segment === '' ? null : substr($segment, 0, 8192),
            limitBeforeGrouping: $limitBeforeGrouping,
            parts: $parts,
            allParts: $allParts,
        );
    }

    /** @return array{int, 'day'|'week'|'month'|'range'|'year', string} */
    private static function reportContext(Request $request): array
    {
        $siteId = self::requiredInteger($request, 'idSite');

        if ($siteId < 1) {
            throw new InvalidApiParameter('idSite');
        }

        $period = self::nullableStringInput($request, 'period');

        if ($period === null || $period === '') {
            throw new MissingApiParameter('period');
        }

        $period = strtolower($period);

        if (! in_array($period, ['day', 'week', 'month', 'year', 'range'], true)) {
            throw new InvalidApiParameter('period', "The period '{$period}' is not supported.");
        }

        $date = self::nullableStringInput($request, 'date');

        if ($date === null || $date === '') {
            throw new MissingApiParameter('date');
        }

        if (! self::validReportDate($date)) {
            throw new InvalidApiParameter('date', "The date '{$date}' is not valid.");
        }

        if ($period === 'range'
            && ! str_contains($date, ',')
            && preg_match('/^(last|previous)[0-9]*$/D', $date) !== 1) {
            throw new InvalidApiParameter('date', "The date '{$date}' is not a valid range.");
        }

        return [$siteId, $period, $date];
    }

    private static function jsTrackerInstallCheck(
        Request $request,
        string $module,
        string $method,
    ): ?JsTrackerInstallCheckRequest {
        if ($module !== 'API' || ! in_array($method, self::JS_TRACKER_INSTALL_CHECK_METHODS, true)) {
            return null;
        }

        return new JsTrackerInstallCheckRequest(
            siteId: self::requiredInteger($request, 'idSite'),
            nonce: self::stringInput($request, 'nonce'),
            url: self::stringInput($request, 'url'),
        );
    }

    private static function goalsReport(
        Request $request,
        string $module,
        string $method,
    ): ?GoalsReportRequest {
        if ($module !== 'API' || ! in_array($method, self::GOALS_REPORT_METHODS, true)) {
            return null;
        }

        $idGoal = self::inputValue($request, 'idGoal');

        if (in_array($idGoal, [null, '', false, 'false'], true)) {
            $idGoal = null;
        } elseif (! is_scalar($idGoal)) {
            throw new InvalidApiParameter('idGoal');
        } elseif (preg_match('/^-?[0-9]+$/D', (string) $idGoal) === 1) {
            $idGoal = (int) $idGoal;
        } else {
            $idGoal = str_replace("\0", '', (string) $idGoal);
        }

        return new GoalsReportRequest(
            abandonedCarts: self::booleanInput($request, 'abandonedCarts', false),
            idGoal: $idGoal,
            showAllGoalSpecificMetrics: self::booleanInput(
                $request,
                'showAllGoalSpecificMetrics',
                false,
            ),
            formatMetrics: self::stringInput($request, 'format_metrics', 'bc') !== '0',
        );
    }

    private static function goals(Request $request, string $module, string $method): ?GoalsRequest
    {
        if ($module !== 'API' || ! in_array($method, self::GOALS_MANAGEMENT_METHODS, true)) {
            return null;
        }

        if ($method === 'Goals.getGoals') {
            [$siteIds, $allSites] = self::reportSiteIds($request);

            return new GoalsRequest(
                siteIds: $siteIds,
                allSites: $allSites,
                idGoal: null,
                orderByName: self::booleanInput($request, 'orderByName', false),
                definition: null,
            );
        }

        $siteId = self::requiredInteger($request, 'idSite');
        $requiresGoalId = in_array($method, [
            'Goals.getGoal',
            'Goals.updateGoal',
            'Goals.deleteGoal',
        ], true);
        $definition = in_array($method, ['Goals.addGoal', 'Goals.updateGoal'], true)
            ? self::goalDefinition($request)
            : null;

        return new GoalsRequest(
            siteIds: [$siteId],
            allSites: false,
            idGoal: $requiresGoalId ? self::requiredInteger($request, 'idGoal') : null,
            orderByName: false,
            definition: $definition,
        );
    }

    private static function goalDefinition(Request $request): GoalDefinition
    {
        $values = [];

        foreach (['name', 'matchAttribute', 'pattern', 'patternType'] as $parameter) {
            $value = self::nullableStringInput($request, $parameter);

            if ($value === null) {
                throw new MissingApiParameter($parameter);
            }

            $values[$parameter] = $value;
        }

        $revenue = self::nullableStringInput($request, 'revenue');

        return new GoalDefinition(
            name: $values['name'],
            matchAttribute: $values['matchAttribute'],
            pattern: $values['pattern'],
            patternType: $values['patternType'],
            caseSensitive: self::booleanInput($request, 'caseSensitive', false),
            revenue: (float) ($revenue ?? '0'),
            allowMultipleConversionsPerVisit: self::booleanInput(
                $request,
                'allowMultipleConversionsPerVisit',
                false,
            ),
            description: self::stringInput($request, 'description'),
            useEventValueAsRevenue: self::booleanInput($request, 'useEventValueAsRevenue', false),
        );
    }

    private static function feedback(Request $request, string $module, string $method): ?FeedbackRequest
    {
        if ($module !== 'API' || ! in_array($method, self::FEEDBACK_METHODS, true)) {
            return null;
        }

        $featureName = self::nullableStringInput($request, 'featureName');
        $question = self::nullableStringInput($request, 'question');

        if ($method === 'Feedback.sendFeedbackForFeature' && $featureName === null) {
            throw new MissingApiParameter('featureName');
        }

        if ($method === 'Feedback.sendFeedbackForSurvey' && $question === null) {
            throw new MissingApiParameter('question');
        }

        return new FeedbackRequest(
            featureName: $featureName,
            like: self::booleanFromArray($request->query->all(), 'like')
                ?? self::booleanFromArray($request->request->all(), 'like'),
            choice: self::nullableStringInput($request, 'choice'),
            message: self::nullableStringInput($request, 'message'),
            question: $question,
        );
    }

    private static function exampleUi(Request $request, string $module, string $method): ?ExampleUiRequest
    {
        if ($module !== 'API' || ! in_array($method, self::EXAMPLE_UI_METHODS, true)) {
            return null;
        }

        if ($method !== 'ExampleUI.getTemperaturesEvolution') {
            return new ExampleUiRequest(null, null);
        }

        $date = self::nullableStringInput($request, 'date');
        $period = self::nullableStringInput($request, 'period');

        if ($date === null) {
            throw new MissingApiParameter('date');
        }

        if ($period === null) {
            throw new MissingApiParameter('period');
        }

        if (! in_array($period, ['day', 'week', 'month', 'year', 'range'], true)) {
            throw new InvalidApiParameter('period', "The period '{$period}' is not supported.");
        }

        return new ExampleUiRequest($date, $period);
    }

    private static function examplePlugin(
        Request $request,
        string $module,
        string $method,
    ): ?ExamplePluginRequest {
        if ($module !== 'API' || ! in_array($method, self::EXAMPLE_PLUGIN_METHODS, true)) {
            return null;
        }

        $segment = self::nullableStringInput($request, 'segment');

        if ($method === 'ExamplePlugin.getSegmentHash' && $segment === null) {
            throw new MissingApiParameter('segment');
        }

        [$siteIds, $allSites] = $method === 'ExamplePlugin.getSegmentHash'
            ? self::reportSiteIds($request)
            : [[], false];

        return new ExamplePluginRequest(
            truth: self::booleanInput($request, 'truth', true),
            segment: $segment,
            siteIds: $siteIds,
            allSites: $allSites,
        );
    }

    private static function exampleApi(Request $request, string $module, string $method): ?ExampleApiRequest
    {
        if ($module !== 'API' || $method !== 'ExampleAPI.getSum') {
            return null;
        }

        return new ExampleApiRequest(
            a: self::floatInput($request, 'a', 0),
            b: self::floatInput($request, 'b', 0),
        );
    }

    private static function dashboard(Request $request, string $module, string $method): ?DashboardRequest
    {
        if ($module !== 'API' || ! in_array($method, self::DASHBOARD_METHODS, true)) {
            return null;
        }

        $requiresId = in_array($method, [
            'Dashboard.removeDashboard',
            'Dashboard.copyDashboardToUser',
            'Dashboard.resetDashboardLayout',
        ], true);
        $dashboardId = $requiresId ? self::requiredInteger($request, 'idDashboard') : null;
        $login = self::legacySanitizedStringInput($request, 'login');
        $copyToUser = self::legacySanitizedStringInput($request, 'copyToUser');

        if ($method === 'Dashboard.createNewDashboardForUser' && $login === '') {
            throw new MissingApiParameter('login');
        }

        if ($method === 'Dashboard.copyDashboardToUser' && $copyToUser === '') {
            throw new MissingApiParameter('copyToUser');
        }

        return new DashboardRequest(
            login: $login,
            dashboardName: self::legacySanitizedStringInput($request, 'dashboardName'),
            copyToUser: $copyToUser,
            dashboardId: $dashboardId,
            returnDefaultIfEmpty: self::booleanFromArray($request->query->all(), 'returnDefaultIfEmpty')
                ?? self::booleanFromArray($request->request->all(), 'returnDefaultIfEmpty')
                ?? true,
            addDefaultWidgets: self::booleanFromArray($request->query->all(), 'addDefaultWidgets')
                ?? self::booleanFromArray($request->request->all(), 'addDefaultWidgets')
                ?? true,
        );
    }

    private static function requiredInteger(Request $request, string $parameter): int
    {
        $value = self::inputValue($request, $parameter);

        if ($value === null || $value === '' || ! is_scalar($value)
            || (string) (int) $value !== (string) $value) {
            throw new MissingApiParameter($parameter);
        }

        return (int) $value;
    }

    private static function integerInput(
        Request $request,
        string $parameter,
        int $default,
        int $minimum,
    ): int {
        $value = self::inputValue($request, $parameter);

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_scalar($value)
            || (string) (int) $value !== (string) $value
            || (int) $value < $minimum) {
            throw new InvalidApiParameter($parameter);
        }

        return (int) $value;
    }

    private static function legacySanitizedStringInput(Request $request, string $key): string
    {
        $value = self::stringInput($request, $key);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML401, 'UTF-8');

        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    private static function aiProvider(Request $request, string $module, string $method): ?AiProviderRequest
    {
        if ($module !== 'API' || ! in_array($method, self::AI_PROVIDERS_METHODS, true)) {
            return null;
        }

        $providerId = self::stringInput($request, 'providerId');

        if (in_array($method, ['AIProviders.testConnection', 'AIProviders.disconnectProvider'], true)
            && $providerId === '') {
            throw new MissingApiParameter('providerId');
        }

        return new AiProviderRequest(
            providerId: $providerId,
            defaultProviderId: self::stringInput($request, 'defaultProviderId'),
            defaultCapabilityLevel: self::stringInput($request, 'defaultCapabilityLevel'),
            providerConfiguration: self::stringInput($request, 'providerConfiguration', '{}'),
            providerConfigurations: self::stringInput($request, 'providerConfigurations', '{}'),
        );
    }

    private static function widgetName(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== self::PROFESSIONAL_SERVICES_METHOD) {
            return null;
        }

        $widgetName = self::nullableStringInput($request, 'widgetName');

        if ($widgetName === null || $widgetName === '') {
            throw new MissingApiParameter('widgetName');
        }

        return $widgetName;
    }

    private static function tourChallengeId(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'Tour.skipChallenge') {
            return null;
        }

        $id = self::nullableStringInput($request, 'id');

        if ($id === null || $id === '') {
            throw new MissingApiParameter('id');
        }

        return $id;
    }

    private static function twoFactorAuth(
        Request $request,
        string $module,
        string $method,
    ): ?TwoFactorAuthRequest {
        if ($module !== 'API' || $method !== 'TwoFactorAuth.resetTwoFactorAuth') {
            return null;
        }

        $userLogin = self::nullableStringInput($request, 'userLogin');

        if ($userLogin === null || $userLogin === '') {
            throw new MissingApiParameter('userLogin');
        }

        return new TwoFactorAuthRequest(
            userLogin: $userLogin,
            passwordConfirmation: self::stringInput($request, 'passwordConfirmation'),
        );
    }

    private static function locationIp(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'UserCountry.getLocationFromIP') {
            return null;
        }

        $ip = self::nullableStringInput($request, 'ip');

        return in_array($ip, [null, '', '0'], true) ? null : $ip;
    }

    private static function locationProviderId(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API') {
            return null;
        }

        if ($method === 'UserCountry.getLocationFromIP') {
            $provider = self::nullableStringInput($request, 'provider');

            return in_array($provider, [null, '', '0'], true) ? null : $provider;
        }

        if ($method !== 'UserCountry.setLocationProvider') {
            return null;
        }

        $provider = self::nullableStringInput($request, 'providerId');

        if ($provider === null || $provider === '') {
            throw new MissingApiParameter('providerId');
        }

        return $provider;
    }

    private static function siteId(Request $request, string $module, string $method): ?int
    {
        $requiredMethods = [
            'SitesManager.getExcludedQueryParameters',
            'SitesManager.getExcludedReferrers',
            'SitesManager.getMessagesToWarnOnSiteRemoval',
            'SitesManager.getSiteFromId',
            'SitesManager.getSiteUrlsFromId',
            'SitesManager.detectConsentManager',
        ];
        $optionalMethods = [
            'SitesManager.getExcludedQueryParametersGlobal',
            'SitesManager.getExclusionTypeForQueryParams',
            'Tour.getChallenges',
            'Tour.getLevel',
            'Tour.skipChallenge',
        ];

        if ($module !== 'API' || ! in_array($method, [
            ...$requiredMethods,
            ...$optionalMethods,
        ], true)) {
            return null;
        }

        $value = self::nullableStringInput($request, 'idSite');

        if (($value === null || $value === '') && in_array($method, $optionalMethods, true)) {
            return null;
        }

        if ($value === null || $value === '' || (string) (int) $value !== $value) {
            throw new MissingApiParameter('idSite');
        }

        return (int) $value;
    }

    /**
     * @return list<string>|null
     */
    private static function timezones(Request $request, string $module, string $method): ?array
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesIdFromTimezones') {
            return null;
        }

        $query = $request->query->all();
        $post = $request->request->all();

        if (array_key_exists('timezones', $query)) {
            $value = $query['timezones'];
        } elseif (array_key_exists('timezones', $post)) {
            $value = $post['timezones'];
        } else {
            throw new MissingApiParameter('timezones');
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);
        $timezones = [];

        foreach ($values as $timezone) {
            if (! is_scalar($timezone)) {
                throw new InvalidApiParameter('timezones');
            }

            $timezones[] = str_replace("\0", '', (string) $timezone);
        }

        return array_values(array_unique($timezones));
    }

    private static function ipRange(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'SitesManager.getIpsForRange') {
            return null;
        }

        $value = self::nullableStringInput($request, 'ipRange');

        if ($value === null) {
            throw new MissingApiParameter('ipRange');
        }

        return $value;
    }

    private static function pluginName(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'API.isPluginActivated') {
            return null;
        }

        $value = self::nullableStringInput($request, 'pluginName');

        if ($value === null) {
            throw new MissingApiParameter('pluginName');
        }

        return $value;
    }

    private static function siteUrl(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesIdFromSiteUrl') {
            return null;
        }

        $value = self::nullableStringInput($request, 'url');

        if ($value === null) {
            throw new MissingApiParameter('url');
        }

        return $value;
    }

    private static function timezone(Request $request, string $module, string $method): ?string
    {
        if ($module !== 'API' || $method !== 'SitesManager.getTimezoneName') {
            return null;
        }

        $value = self::nullableStringInput($request, 'timezone');

        if ($value === null) {
            throw new MissingApiParameter('timezone');
        }

        return $value;
    }

    private static function timezoneCountryCode(Request $request, string $module, string $method): ?string
    {
        return $module === 'API' && $method === 'SitesManager.getTimezoneName'
            ? self::nullableStringInput($request, 'countryCode')
            : null;
    }

    private static function multipleTimezonesInCountry(
        Request $request,
        string $module,
        string $method,
    ): ?bool {
        if ($module !== 'API' || $method !== 'SitesManager.getTimezoneName') {
            return null;
        }

        return self::booleanFromArray($request->query->all(), 'multipleTimezonesInCountry')
            ?? self::booleanFromArray($request->request->all(), 'multipleTimezonesInCountry');
    }

    private static function siteGroup(Request $request, string $module, string $method): ?string
    {
        return $module === 'API' && $method === 'SitesManager.getSitesFromGroup'
            ? trim(self::stringInput($request, 'group'))
            : null;
    }

    private static function fetchAliasUrls(Request $request, string $module, string $method): bool
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesWithAdminAccess') {
            return false;
        }

        return self::booleanFromArray($request->query->all(), 'fetchAliasUrls')
            ?? self::booleanFromArray($request->request->all(), 'fetchAliasUrls')
            ?? false;
    }

    private static function sitePattern(Request $request, string $module, string $method): ?string
    {
        if (! self::supportsSiteListFilters($module, $method)) {
            return null;
        }

        $pattern = self::nullableStringInput($request, 'pattern');

        if ($method === 'SitesManager.getPatternMatchSites' && $pattern === null) {
            throw new MissingApiParameter('pattern');
        }

        if ($method === 'SitesManager.getSitesWithMinimumAccess' && ($pattern === '' || $pattern === '0')) {
            return null;
        }

        return $pattern;
    }

    private static function siteLimit(Request $request, string $module, string $method): ?int
    {
        if (! self::supportsSiteLimit($module, $method)) {
            return null;
        }

        $value = self::nullableStringInput($request, 'limit');

        if ($value === null || $value === '' || (string) (int) $value !== $value || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private static function siteContentTimeout(Request $request, string $module, string $method): ?int
    {
        if ($module !== 'API' || $method !== 'SitesManager.detectConsentManager') {
            return null;
        }

        $value = self::nullableStringInput($request, 'timeOut');

        if ($value === null || $value === '') {
            return 60;
        }

        if ((string) (int) $value !== $value) {
            throw new InvalidApiParameter('timeOut');
        }

        return max(1, min((int) $value, 60));
    }

    /**
     * @return list<int>
     */
    private static function sitesToExclude(Request $request, string $module, string $method): array
    {
        if (! self::supportsSiteListFilters($module, $method)) {
            return [];
        }

        $query = $request->query->all();
        $post = $request->request->all();

        if (array_key_exists('sitesToExclude', $query)) {
            $value = $query['sitesToExclude'];
        } elseif (array_key_exists('sitesToExclude', $post)) {
            $value = $post['sitesToExclude'];
        } else {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);
        $siteIds = [];

        foreach ($values as $siteId) {
            if (! is_scalar($siteId) || (string) (int) $siteId !== (string) $siteId) {
                throw new InvalidApiParameter('sitesToExclude');
            }

            $siteIds[] = (int) $siteId;
        }

        return array_values(array_unique($siteIds));
    }

    private static function minimumSiteAccessRole(
        Request $request,
        string $module,
        string $method,
    ): ?SiteAccessRole {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesWithMinimumAccess') {
            return null;
        }

        $permission = self::nullableStringInput($request, 'permission');

        if ($permission === null || $permission === '') {
            throw new MissingApiParameter('permission');
        }

        return SiteAccessRole::tryFrom(strtolower($permission))
            ?? throw new InvalidApiParameter('permission', 'Invalid permission provided');
    }

    /**
     * @return list<string>
     */
    private static function siteTypesToExclude(Request $request, string $module, string $method): array
    {
        if ($module !== 'API' || $method !== 'SitesManager.getSitesWithMinimumAccess') {
            return [];
        }

        $query = $request->query->all();
        $post = $request->request->all();

        if (array_key_exists('siteTypesToExclude', $query)) {
            $value = $query['siteTypesToExclude'];
        } elseif (array_key_exists('siteTypesToExclude', $post)) {
            $value = $post['siteTypesToExclude'];
        } else {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);
        $siteTypes = [];

        foreach ($values as $siteType) {
            if (! is_scalar($siteType)) {
                throw new InvalidApiParameter('siteTypesToExclude');
            }

            $siteTypes[] = str_replace("\0", '', (string) $siteType);
        }

        return array_values(array_unique($siteTypes));
    }

    private static function visitsSummary(
        Request $request,
        string $module,
        string $method,
    ): ?VisitsSummaryRequest {
        if ($module !== 'API'
            || (! in_array($method, self::VISITS_SUMMARY_METHODS, true)
                && $method !== 'VisitFrequency.get'
                && ! in_array($method, self::VISIT_TIME_METHODS, true)
                && ! in_array($method, self::VISITOR_INTEREST_METHODS, true)
                && ! in_array($method, self::USER_LANGUAGE_METHODS, true)
                && ! in_array($method, self::RESOLUTION_METHODS, true)
                && $method !== self::DEVICE_PLUGINS_METHOD
                && ! in_array($method, self::DEVICES_DETECTION_METHODS, true)
                && $method !== self::PAGE_PERFORMANCE_METHOD
                && $method !== self::USER_ID_METHOD
                && ! in_array($method, self::ACTIONS_METHODS, true)
                && ! in_array($method, self::EXAMPLE_PLUGIN_REPORT_METHODS, true)
                && $method !== self::EXAMPLE_REPORT_METHOD
                && ! in_array($method, self::EVENTS_METHODS, true)
                && ! in_array($method, self::CONTENTS_METHODS, true)
                && ! in_array($method, self::BOT_TRACKING_ARCHIVE_METHODS, true)
                && ! in_array($method, self::GOALS_REPORT_METHODS, true)
                && $method !== 'CustomDimensions.getCustomDimension'
                && $method !== self::AI_AGENTS_METHOD
                && ! in_array($method, [
                    'UserCountry.getCountry',
                    'UserCountry.getContinent',
                    'UserCountry.getRegion',
                    'UserCountry.getCity',
                    'UserCountry.getNumberOfDistinctCountries',
                ], true))) {
            return null;
        }

        [$siteIds, $allSites] = self::reportSiteIds($request);
        $period = self::nullableStringInput($request, 'period');
        $date = self::nullableStringInput($request, 'date');

        if ($period === null || $period === '') {
            throw new MissingApiParameter('period');
        }

        if (! in_array($period, ['day', 'week', 'month', 'year', 'range'], true)) {
            throw new InvalidApiParameter('period', "The period '{$period}' is not supported.");
        }

        if ($date === null || $date === '') {
            throw new MissingApiParameter('date');
        }

        if (! self::validReportDate($date)) {
            throw new InvalidApiParameter('date', "The date '{$date}' is not valid.");
        }

        if ($period === 'range'
            && ! str_contains($date, ',')
            && preg_match('/^(last|previous)[0-9]*$/D', $date) !== 1) {
            throw new InvalidApiParameter('date', "The date '{$date}' is not a valid range.");
        }

        $segment = self::nullableStringInput($request, 'segment');

        if (in_array($method, self::BOT_TRACKING_ARCHIVE_METHODS, true)) {
            $segment = null;
        }

        return new VisitsSummaryRequest(
            siteIds: $siteIds,
            allSites: $allSites,
            period: $period,
            date: $date,
            segment: $segment === null || trim($segment) === '' ? null : trim($segment),
            columns: self::reportColumnList($request, 'columns', true),
            showColumns: self::reportColumnList($request, 'showColumns') ?? [],
            hideColumns: self::reportColumnList($request, 'hideColumns') ?? [],
            idSubtable: self::reportSubtableId($request, $method),
            expanded: self::booleanInput($request, 'expanded', false),
            secondaryDimension: self::reportSecondaryDimension($request, $method),
            flat: self::booleanInput($request, 'flat', false),
            showDimensions: self::booleanInput($request, 'show_dimensions', false),
        );
    }

    private static function reportSubtableId(Request $request, string $method): ?int
    {
        if (! in_array($method, self::CONTENTS_METHODS, true)
            && ! in_array($method, self::EVENTS_SUBTABLE_METHODS, true)
            && ! in_array($method, self::ACTIONS_SUBTABLE_METHODS, true)
            && ! in_array($method, self::BOT_TRACKING_SUBTABLE_METHODS, true)
            && $method !== 'CustomDimensions.getCustomDimension') {
            return null;
        }

        $value = self::inputValue($request, 'idSubtable');

        if (in_array($value, [null, '', false, 'false', '0'], true)) {
            if ((in_array($method, self::EVENTS_SUBTABLE_METHODS, true)
                || in_array($method, self::BOT_TRACKING_SUBTABLE_METHODS, true))
                && ! in_array($value, ['0', 0], true)) {
                throw new MissingApiParameter('idSubtable');
            }

            return null;
        }

        if (! is_scalar($value) || (string) (int) $value !== (string) $value || (int) $value < 1) {
            throw new InvalidApiParameter(
                'idSubtable',
                "The parameter 'idSubtable' has an invalid value.",
            );
        }

        return (int) $value;
    }

    private static function multiSites(
        Request $request,
        string $module,
        string $method,
    ): ?MultiSitesRequest {
        if ($module !== 'API' || ! in_array($method, self::MULTI_SITES_METHODS, true)) {
            return null;
        }

        $usesDefaults = $method === 'MultiSites.getAllWithGroups';
        $period = self::nullableStringInput($request, 'period');
        $date = self::nullableStringInput($request, 'date');
        $period = $period === null || $period === ''
            ? ($usesDefaults ? 'day' : null)
            : strtolower($period);
        $date = $date === null || $date === ''
            ? ($usesDefaults ? 'today' : null)
            : $date;

        if ($period === null) {
            throw new MissingApiParameter('period');
        }

        if (! in_array($period, ['day', 'week', 'month', 'year', 'range'], true)) {
            throw new InvalidApiParameter('period', "The period '{$period}' is not supported.");
        }

        if ($date === null) {
            throw new MissingApiParameter('date');
        }

        if (! self::validReportDate($date)) {
            throw new InvalidApiParameter('date', "The date '{$date}' is not valid.");
        }

        if ($period === 'range'
            && ! str_contains($date, ',')
            && preg_match('/^(last|previous)[0-9]*$/D', $date) !== 1) {
            throw new InvalidApiParameter('date', "The date '{$date}' is not a valid range.");
        }

        $segment = trim(self::nullableStringInput($request, 'segment') ?? '');
        $siteId = $method === 'MultiSites.getOne'
            ? self::requiredInteger($request, 'idSite')
            : null;

        if ($siteId !== null && $siteId < 1) {
            throw new InvalidApiParameter('idSite');
        }

        return new MultiSitesRequest(
            siteId: $siteId,
            period: $period,
            date: $date,
            segment: $segment === '' ? null : substr($segment, 0, 8192),
            enhanced: self::booleanInput($request, 'enhanced', false),
            pattern: self::nullableStringInput($request, 'pattern'),
            showColumns: self::reportColumnList($request, 'showColumns') ?? [],
            filterLimit: self::integerInput($request, 'filter_limit', $usesDefaults ? 0 : -1, -1),
            filterOffset: self::integerInput($request, 'filter_offset', 0, 0),
            filterSortColumn: self::stringInput($request, 'filter_sort_column', 'nb_visits'),
            filterSortOrder: strtolower(self::stringInput($request, 'filter_sort_order', 'desc')),
            formatMetrics: self::booleanInput($request, 'format_metrics', $usesDefaults),
        );
    }

    private static function actions(Request $request, string $module, string $method): ?ActionsRequest
    {
        if ($module !== 'API' || ! in_array($method, self::ACTIONS_METHODS, true)) {
            return null;
        }

        $parameter = match ($method) {
            'Actions.getPageUrl' => 'pageUrl',
            'Actions.getPageTitle' => 'pageName',
            'Actions.getDownload' => 'downloadUrl',
            'Actions.getOutlink' => 'outlinkUrl',
            default => null,
        };
        $depth = self::inputValue($request, 'depth');

        if (in_array($depth, [null, '', false, 'false', '0'], true)) {
            $parsedDepth = null;
        } elseif (! is_scalar($depth)
            || (string) (int) $depth !== (string) $depth
            || (int) $depth < 1) {
            throw new InvalidApiParameter('depth');
        } else {
            $parsedDepth = (int) $depth;
        }

        return new ActionsRequest(
            actionValue: $parameter === null ? null : self::requiredString($request, $parameter),
            depth: $parsedDepth,
        );
    }

    private static function annotations(
        Request $request,
        string $module,
        string $method,
    ): ?AnnotationRequest {
        if ($module !== 'API' || ! in_array($method, self::ANNOTATION_METHODS, true)) {
            return null;
        }

        [$siteIds, $allSites] = self::reportSiteIds($request);
        $singleSite = ! in_array($method, [
            'Annotations.getAll',
            'Annotations.getAnnotationCountForDates',
        ], true);

        if ($singleSite && ($allSites || count($siteIds) !== 1)) {
            throw new InvalidApiParameter('idSite', 'This API method requires one website ID.');
        }

        $requiresNoteId = in_array($method, [
            'Annotations.save',
            'Annotations.delete',
            'Annotations.get',
        ], true);
        $noteId = $requiresNoteId ? self::requiredInteger($request, 'idNote') : null;

        if ($noteId !== null && $noteId < 1) {
            throw new InvalidApiParameter('idNote');
        }

        $requiresDate = in_array($method, [
            'Annotations.add',
            'Annotations.getAnnotationCountForDates',
        ], true);
        $date = $requiresDate
            ? self::requiredString($request, 'date')
            : self::nullableStringInput($request, 'date');
        $note = $method === 'Annotations.add'
            ? self::requiredString($request, 'note')
            : self::nullableStringInput($request, 'note');
        $starred = self::booleanFromArray($request->query->all(), 'starred')
            ?? self::booleanFromArray($request->request->all(), 'starred');
        $period = self::stringInput($request, 'period', 'day');

        if (! in_array($period, ['day', 'week', 'month', 'year', 'range'], true)) {
            throw new InvalidApiParameter('period', "The period '{$period}' is not supported.");
        }

        $lastNValue = self::inputValue($request, 'lastN');
        $lastN = null;

        if (! in_array($lastNValue, [null, '', false, 'false', '0'], true)) {
            if (! is_scalar($lastNValue)
                || (string) (int) $lastNValue !== (string) $lastNValue
                || (int) $lastNValue < 1) {
                throw new InvalidApiParameter('lastN');
            }

            $lastN = min((int) $lastNValue, match ($period) {
                'day', 'range' => 1825,
                'week' => 520,
                'month' => 120,
                'year' => 10,
            });
        }

        return new AnnotationRequest(
            siteIds: $siteIds,
            allSites: $allSites,
            noteId: $noteId,
            date: $date,
            note: $note,
            starred: $starred,
            period: $period,
            lastN: $lastN,
            includeText: self::booleanInput($request, 'getAnnotationText', false),
        );
    }

    private static function reportSecondaryDimension(Request $request, string $method): ?string
    {
        $allowed = match ($method) {
            'Events.getCategory' => ['eventAction', 'eventName'],
            'Events.getAction' => ['eventName', 'eventCategory'],
            'Events.getName' => ['eventAction', 'eventCategory'],
            'BotTracking.getAIChatbotRequests' => ['pages', 'documents'],
            default => null,
        };

        if ($allowed === null) {
            return null;
        }

        $secondaryDimension = self::nullableStringInput($request, 'secondaryDimension');

        if ($secondaryDimension === null || $secondaryDimension === '') {
            return null;
        }

        if (! in_array($secondaryDimension, $allowed, true)) {
            throw new InvalidApiParameter(
                'secondaryDimension',
                "Secondary dimension '{$secondaryDimension}' is not valid for the API ".
                    substr($method, strpos($method, '.') + 1).'. Use one of: '.implode(', ', $allowed),
            );
        }

        return $secondaryDimension;
    }

    /**
     * @return array{list<int>, bool}
     */
    private static function reportSiteIds(Request $request): array
    {
        $input = self::inputValue($request, 'idSite');

        if ($input === null || $input === '') {
            throw new MissingApiParameter('idSite');
        }

        if ($input === 'all' || $input === ['all']) {
            return [[], true];
        }

        $values = is_array($input) ? $input : explode(',', (string) $input);
        $siteIds = [];

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_scalar($value)) {
                throw new InvalidApiParameter('idSite');
            }

            $value = is_string($value) ? trim($value) : $value;

            if ((string) (int) $value !== (string) $value || (int) $value < 1) {
                throw new InvalidApiParameter('idSite', "The parameter 'idSite=' contains an invalid value.");
            }

            $siteIds[] = (int) $value;
        }

        if ($siteIds === []) {
            throw new InvalidApiParameter('idSite', "The parameter 'idSite=' contains an invalid value.");
        }

        return [array_values(array_unique($siteIds)), false];
    }

    /**
     * @return list<string>|null
     */
    private static function reportColumnList(
        Request $request,
        string $parameter,
        bool $emptyMeansAll = false,
    ): ?array {
        $input = self::inputValue($request, $parameter);

        if ($input === null) {
            return null;
        }

        $values = is_array($input) ? $input : explode(',', (string) $input);
        $columns = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                throw new InvalidApiParameter($parameter);
            }

            $columns[] = str_replace("\0", '', (string) $value);
        }

        $columns = array_values(array_unique(array_filter(
            $columns,
            static fn (string $column): bool => $column !== '',
        )));

        return $columns === [] && $emptyMeansAll ? null : $columns;
    }

    private static function validReportDate(string $date): bool
    {
        if (preg_match('/^(now|today|yesterday|yesterdaySameTime|last[ -]?(?:week|month|year))$/iD', $date) === 1) {
            return true;
        }

        if (preg_match('/^(last|previous)[0-9]*$/D', $date) === 1) {
            return true;
        }

        $dates = explode(',', $date);

        if (count($dates) === 1) {
            return self::validIsoDate($dates[0]);
        }

        if (count($dates) !== 2) {
            return false;
        }

        return self::validReportDateRangeBoundary($dates[0], false)
            && self::validReportDateRangeBoundary($dates[1], true);
    }

    private static function validReportDateRangeBoundary(string $date, bool $isEnd): bool
    {
        if (self::validIsoDate($date)) {
            return true;
        }

        if (preg_match('/^last[ -]?(?:week|month|year)$/iD', $date) === 1) {
            return true;
        }

        return $isEnd && preg_match('/^(today|now|yesterday)$/iD', $date) === 1;
    }

    private static function validIsoDate(string $date): bool
    {
        if (preg_match('/^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2})$/D', $date, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function inputValue(Request $request, string $key): mixed
    {
        $query = $request->query->all();

        if (array_key_exists($key, $query)) {
            return $query[$key];
        }

        $post = $request->request->all();

        return $post[$key] ?? null;
    }

    private static function requiredBoolean(Request $request, string $key): bool
    {
        $value = self::inputValue($request, $key);

        if ($value === null) {
            throw new MissingApiParameter($key);
        }

        if (! is_scalar($value)) {
            throw new InvalidApiParameter($key);
        }

        return self::booleanFromArray($request->query->all(), $key)
            ?? self::booleanFromArray($request->request->all(), $key)
            ?? false;
    }

    private static function requiredString(Request $request, string $key): string
    {
        return self::nullableStringInput($request, $key)
            ?? throw new MissingApiParameter($key);
    }

    /** @return list<string> */
    private static function requiredStringList(Request $request, string $key): array
    {
        $value = self::inputValue($request, $key);

        if ($value === null) {
            throw new MissingApiParameter($key);
        }

        return self::stringList($value, $key);
    }

    /** @return list<string> */
    private static function stringList(
        mixed $value,
        string $key,
        bool $splitCommaSeparated = true,
    ): array {
        if (! is_array($value) && ! is_scalar($value)) {
            throw new InvalidApiParameter($key);
        }

        $values = is_array($value)
            ? $value
            : ($splitCommaSeparated ? explode(',', (string) $value) : [$value]);
        $result = [];

        foreach ($values as $item) {
            if (! is_scalar($item)) {
                throw new InvalidApiParameter($key);
            }

            $result[] = trim(str_replace("\0", '', (string) $item));
        }

        return array_values(array_unique($result));
    }

    private static function supportsSiteListFilters(string $module, string $method): bool
    {
        return $module === 'API' && in_array($method, [
            'SitesManager.getSitesWithAdminAccess',
            'SitesManager.getSitesWithMinimumAccess',
            'SitesManager.getPatternMatchSites',
        ], true);
    }

    private static function supportsSiteLimit(string $module, string $method): bool
    {
        return self::supportsSiteListFilters($module, $method)
            || ($module === 'API' && $method === 'SitesManager.getSitesWithAtLeastViewAccess');
    }

    private static function authentication(Request $request): ApiAuthentication
    {
        $authorization = $request->headers->get('Authorization');
        $headerToken = is_string($authorization) && str_starts_with($authorization, 'Bearer ')
            ? substr($authorization, 7)
            : null;
        $postToken = self::stringFromArray($request->request->all(), 'token_auth');
        $queryToken = self::stringFromArray($request->query->all(), 'token_auth');
        $postForceSession = self::booleanFromArray($request->request->all(), 'force_api_session');
        $queryForceSession = self::booleanFromArray($request->query->all(), 'force_api_session');

        $providedTokens = array_filter(
            [$headerToken, $postToken, $queryToken],
            static fn (?string $token): bool => ! empty($token),
        );

        if (count(array_unique($providedTokens)) > 1) {
            throw new ConflictingAuthenticationParameters;
        }

        if (
            $postForceSession !== null
            && $queryForceSession !== null
            && $postForceSession !== $queryForceSession
        ) {
            throw new ConflictingAuthenticationParameters;
        }

        if ($headerToken !== null) {
            return new ApiAuthentication($headerToken, true, false, self::sessionId($request));
        }

        if ($postToken !== null && $postToken !== '') {
            return new ApiAuthentication(
                $postToken,
                true,
                $postForceSession ?? false,
                self::sessionId($request),
            );
        }

        return new ApiAuthentication(
            $queryToken,
            false,
            $queryToken !== null && $queryToken !== '' && ($queryForceSession ?? false),
            self::sessionId($request),
        );
    }

    private static function stringInput(Request $request, string $key, string $default = ''): string
    {
        return self::nullableStringInput($request, $key) ?? $default;
    }

    private static function nullableStringInput(Request $request, string $key): ?string
    {
        $queryValue = self::stringFromArray($request->query->all(), $key);

        return $queryValue ?? self::stringFromArray($request->request->all(), $key);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function stringFromArray(array $input, string $key): ?string
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];

        // Laravel converts empty form fields to null before this boundary.
        // Matomo's API treats those fields as empty strings.
        if ($value === null) {
            return '';
        }

        if (! is_scalar($value)) {
            throw new InvalidApiParameter($key);
        }

        return str_replace("\0", '', (string) $value);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function booleanFromArray(array $input, string $key): ?bool
    {
        if (! array_key_exists($key, $input)) {
            return null;
        }

        $value = $input[$key];

        if (in_array($value, [true, 1, '1'], true) || (is_string($value) && strtolower($value) === 'true')) {
            return true;
        }

        if (in_array($value, [false, 0, '0'], true) || (is_string($value) && strtolower($value) === 'false')) {
            return false;
        }

        return false;
    }

    private static function sessionId(Request $request): ?string
    {
        $sessionId = $request->cookies->get('MATOMO_SESSID');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private static function safeStringInput(Request $request, string $key, string $default = ''): string
    {
        return self::safeNullableStringInput($request, $key) ?? $default;
    }

    private static function safeNullableStringInput(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? $request->request->all()[$key] ?? null;

        return is_scalar($value) ? str_replace("\0", '', (string) $value) : null;
    }

    private static function booleanInput(Request $request, string $key, bool $default): bool
    {
        $value = self::safeNullableStringInput($request, $key);

        return match (strtolower($value ?? '')) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => $default,
        };
    }

    private static function floatInput(Request $request, string $key, float $default): float
    {
        $value = self::safeNullableStringInput($request, $key);

        return $value !== null && preg_match(self::FLOAT_PATTERN, $value) === 1
            ? (float) str_replace('_', '', $value)
            : $default;
    }
}
