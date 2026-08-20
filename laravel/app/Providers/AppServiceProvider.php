<?php

declare(strict_types=1);

namespace App\Providers;

use App\Matomo\AiProviders\AiProviderCatalog;
use App\Matomo\AiProviders\AiProviderCentralConfiguration;
use App\Matomo\AiProviders\AiProviderConnectionTester;
use App\Matomo\AiProviders\AiProviderSettingsManager;
use App\Matomo\AiProviders\AiProviderSettingsRepository;
use App\Matomo\AiProviders\BuiltInAiProviderCatalog;
use App\Matomo\AiProviders\DatabaseAiProviderSettingsRepository;
use App\Matomo\AiProviders\HttpAiProviderConnectionTester;
use App\Matomo\Api\Methods\AiAgentsApiMethodHandler;
use App\Matomo\Api\Methods\AiProvidersApiMethodHandler;
use App\Matomo\Api\Methods\ApiMethodDispatcher;
use App\Matomo\Api\Methods\ContentsApiMethodHandler;
use App\Matomo\Api\Methods\CoreAdminHomeApiMethodHandler;
use App\Matomo\Api\Methods\CoreApiMethodHandler;
use App\Matomo\Api\Methods\CustomJsTrackerApiMethodHandler;
use App\Matomo\Api\Methods\DashboardApiMethodHandler;
use App\Matomo\Api\Methods\DevicePluginsApiMethodHandler;
use App\Matomo\Api\Methods\DevicesDetectionApiMethodHandler;
use App\Matomo\Api\Methods\EventsApiMethodHandler;
use App\Matomo\Api\Methods\ExampleApiMethodHandler;
use App\Matomo\Api\Methods\ExamplePluginApiMethodHandler;
use App\Matomo\Api\Methods\ExampleReportApiMethodHandler;
use App\Matomo\Api\Methods\ExampleUiApiMethodHandler;
use App\Matomo\Api\Methods\FeedbackApiMethodHandler;
use App\Matomo\Api\Methods\GoalsApiMethodHandler;
use App\Matomo\Api\Methods\GoalsReportApiMethodHandler;
use App\Matomo\Api\Methods\JsTrackerInstallCheckApiMethodHandler;
use App\Matomo\Api\Methods\LanguagesManagerApiMethodHandler;
use App\Matomo\Api\Methods\LoginApiMethodHandler;
use App\Matomo\Api\Methods\OverlayApiMethodHandler;
use App\Matomo\Api\Methods\PagePerformanceApiMethodHandler;
use App\Matomo\Api\Methods\ProfessionalServicesApiMethodHandler;
use App\Matomo\Api\Methods\ResolutionApiMethodHandler;
use App\Matomo\Api\Methods\SitesManagerApiMethodHandler;
use App\Matomo\Api\Methods\TourApiMethodHandler;
use App\Matomo\Api\Methods\TransitionsApiMethodHandler;
use App\Matomo\Api\Methods\TwoFactorAuthApiMethodHandler;
use App\Matomo\Api\Methods\UserCountryApiMethodHandler;
use App\Matomo\Api\Methods\UserIdApiMethodHandler;
use App\Matomo\Api\Methods\UserLanguageApiMethodHandler;
use App\Matomo\Api\Methods\VisitFrequencyApiMethodHandler;
use App\Matomo\Api\Methods\VisitorInterestApiMethodHandler;
use App\Matomo\Api\Methods\VisitsSummaryApiMethodHandler;
use App\Matomo\Api\Methods\VisitTimeApiMethodHandler;
use App\Matomo\Archiving\ArchiveActionQueryFactory;
use App\Matomo\Archiving\ArchiveConversionQueryFactory;
use App\Matomo\Archiving\ArchiveInvalidationManager;
use App\Matomo\Archiving\ArchiveVisitQueryFactory;
use App\Matomo\Archiving\BrowserLanguageArchiveLabeler;
use App\Matomo\Archiving\BuiltInVisitSegmentApplicator;
use App\Matomo\Archiving\CarbonReportingSubperiodFactory;
use App\Matomo\Archiving\ContentArchiveCollector;
use App\Matomo\Archiving\ConversionSegmentApplicator;
use App\Matomo\Archiving\DatabaseArchiveInvalidationManager;
use App\Matomo\Archiving\DatabaseReportArchiver;
use App\Matomo\Archiving\EcommerceItemArchiveCollector;
use App\Matomo\Archiving\EventArchiveCollector;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Archiving\GoalArchiveCollector;
use App\Matomo\Archiving\ReportArchiver;
use App\Matomo\Archiving\ReportingSubperiodFactory;
use App\Matomo\Archiving\SegmentDefinitionValidator;
use App\Matomo\Archiving\VisitAggregateArchiveCollector;
use App\Matomo\Archiving\VisitDimensionArchiveCollector;
use App\Matomo\Archiving\VisitSegmentApplicator;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseApiAccessAuthorizer;
use App\Matomo\Authentication\DatabasePasswordConfirmationVerifier;
use App\Matomo\Authentication\DatabaseSessionAuthenticator;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Config\InstallationConfig;
use App\Matomo\CoreAdmin\BrandingManager;
use App\Matomo\CoreAdmin\ConfiguredCoreAdminSettings;
use App\Matomo\CoreAdmin\CoreAdminSettings;
use App\Matomo\CoreAdmin\FileBrandingManager;
use App\Matomo\CoreAdmin\IniTrustedHostConfiguration;
use App\Matomo\CoreAdmin\OptOutEmbedCodeGenerator;
use App\Matomo\CoreAdmin\TranslatedOptOutEmbedCodeGenerator;
use App\Matomo\CoreAdmin\TrustedHostConfiguration;
use App\Matomo\Dashboard\ConfiguredDashboardLayoutProvider;
use App\Matomo\Dashboard\DashboardLayoutProvider;
use App\Matomo\Dashboard\DashboardRecipientPolicy;
use App\Matomo\Dashboard\DashboardRepository;
use App\Matomo\Dashboard\DatabaseDashboardRecipientPolicy;
use App\Matomo\Dashboard\DatabaseDashboardRepository;
use App\Matomo\Database\MatomoDatabase;
use App\Matomo\Feedback\ConfiguredFeedbackSettings;
use App\Matomo\Feedback\DatabaseFeedbackStore;
use App\Matomo\Feedback\FeedbackFeatureNameResolver;
use App\Matomo\Feedback\FeedbackMailer;
use App\Matomo\Feedback\FeedbackSettings;
use App\Matomo\Feedback\FeedbackStore;
use App\Matomo\Feedback\JsonFeedbackFeatureNameResolver;
use App\Matomo\Feedback\LaravelFeedbackMailer;
use App\Matomo\Geolocation\ConfiguredGeolocationProviderRegistry;
use App\Matomo\Geolocation\ConfiguredGeolocationSettings;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Geolocation\DatabaseServerVariableMapping;
use App\Matomo\Geolocation\DisabledGeolocationProvider;
use App\Matomo\Geolocation\FileTrackerCacheInvalidator;
use App\Matomo\Geolocation\GeolocationProviderRegistry;
use App\Matomo\Geolocation\GeolocationSettings;
use App\Matomo\Geolocation\LanguageGeolocationProvider;
use App\Matomo\Geolocation\LocalizedCountryMetadataProvider;
use App\Matomo\Geolocation\MaxMindDatabaseGeolocationProvider;
use App\Matomo\Geolocation\ServerModuleGeolocationProvider;
use App\Matomo\Geolocation\ServerVariableMapping;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\DatabaseGoalRepository;
use App\Matomo\Goals\FileSiteTrackerCacheInvalidator;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Localization\ApiLanguageResolver;
use App\Matomo\Localization\DatabaseLanguagePreferenceRepository;
use App\Matomo\Localization\FilesystemLanguageCatalog;
use App\Matomo\Localization\JsonMatomoTranslator;
use App\Matomo\Localization\LanguageCatalog;
use App\Matomo\Localization\LanguagePreferenceRepository;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Localization\MutableLanguagePreferenceRepository;
use App\Matomo\Login\BruteForceSettings;
use App\Matomo\Login\BruteForceUnblocker;
use App\Matomo\Login\DatabaseBruteForceSettings;
use App\Matomo\Login\DatabaseBruteForceUnblocker;
use App\Matomo\Login\DatabaseLoginAttemptGuard;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Plugins\ConfiguredPluginState;
use App\Matomo\Plugins\LocalTrackerFileAvailability;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Plugins\TrackerFileAvailability;
use App\Matomo\ProfessionalServices\DatabasePromoWidgetDismissalRepository;
use App\Matomo\ProfessionalServices\PromoWidgetDismissalRepository;
use App\Matomo\Reporting\BatchBlobArchiveRepository;
use App\Matomo\Reporting\BlobArchiveMetadataRepository;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\CarbonReportingPeriodFactory;
use App\Matomo\Reporting\ConfiguredDeviceModelPolicy;
use App\Matomo\Reporting\ConfiguredReportingSettings;
use App\Matomo\Reporting\ConfiguredScreenResolutionPolicy;
use App\Matomo\Reporting\DatabaseBlobArchiveRepository;
use App\Matomo\Reporting\DatabaseSegmentHashResolver;
use App\Matomo\Reporting\DatabaseVisitsSummaryArchiveRepository;
use App\Matomo\Reporting\DeviceDetectionMetadata;
use App\Matomo\Reporting\DeviceModelPolicy;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\ScreenResolutionPolicy;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Scheduling\DatabaseScheduledTaskLock;
use App\Matomo\Scheduling\DatabaseScheduledTaskRunner;
use App\Matomo\Scheduling\ScheduledTaskLock;
use App\Matomo\Scheduling\ScheduledTaskRunner;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ConfiguredReportingApiIpAllowlist;
use App\Matomo\Security\EgressHostResolver;
use App\Matomo\Security\ReportingApiIpAllowlist;
use App\Matomo\Settings\DatabasePolicySettingRepository;
use App\Matomo\Settings\PolicySettingRepository;
use App\Matomo\Sites\ConfiguredCurrencyProvider;
use App\Matomo\Sites\ConfiguredQueryParameterExclusionPolicy;
use App\Matomo\Sites\ConfiguredSiteRuntimeSettings;
use App\Matomo\Sites\ConsentManagerDetector;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\DatabaseSiteRepository;
use App\Matomo\Sites\HttpConsentManagerDetector;
use App\Matomo\Sites\LocalizedSiteDetailsPresenter;
use App\Matomo\Sites\LocalizedTimezoneProvider;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteDetailsPresenter;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Sites\TimezoneProvider;
use App\Matomo\Tour\ConfiguredTourSettings;
use App\Matomo\Tour\DatabaseTourDataRepository;
use App\Matomo\Tour\TourDataRepository;
use App\Matomo\Tour\TourSettings;
use App\Matomo\TrackingFailures\DatabaseTrackingFailureRepository;
use App\Matomo\TrackingFailures\TrackingFailureRepository;
use App\Matomo\Transitions\ConfiguredTransitionsPeriodPolicy;
use App\Matomo\Transitions\ConfiguredTransitionsSettings;
use App\Matomo\Transitions\TransitionsPeriodPolicy;
use App\Matomo\Transitions\TransitionsSettings;
use App\Matomo\TwoFactorAuth\DatabaseTwoFactorAuthenticationResetter;
use App\Matomo\TwoFactorAuth\TwoFactorAuthenticationResetter;
use App\Matomo\UserChanges\DatabaseUserChangeReadRepository;
use App\Matomo\UserChanges\UserChangeReadRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InstallationConfig::class, function (Application $application): InstallationConfig {
            $path = $application->make(Repository::class)->get('matomo.config_path');

            if (! is_string($path)) {
                throw new RuntimeException('The Matomo configuration path is invalid.');
            }

            return InstallationConfig::fromFile($path);
        });

        $this->app->singleton(
            MatomoDatabase::class,
            function (Application $application): MatomoDatabase {
                $installation = $application->make(InstallationConfig::class);
                $configuration = $application->make(Repository::class);
                $databases = $application->make(DatabaseManager::class);

                $configuration->set('database.connections.matomo', $installation->databaseConnection());
                $databases->purge('matomo');

                return new MatomoDatabase($databases->connection('matomo'));
            },
        );

        $this->app->singleton(AiProviderCatalog::class, BuiltInAiProviderCatalog::class);
        $this->app->singleton(
            TrustedHostConfiguration::class,
            function (Application $application): TrustedHostConfiguration {
                $path = $application->make(Repository::class)->get('matomo.config_path');

                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('The Matomo configuration path is invalid.');
                }

                return new IniTrustedHostConfiguration(
                    $path,
                    $application->make(Dispatcher::class),
                );
            },
        );
        $this->app->singleton(CoreAdminSettings::class, ConfiguredCoreAdminSettings::class);
        $this->app->singleton(
            OptOutEmbedCodeGenerator::class,
            fn (Application $application): OptOutEmbedCodeGenerator => new TranslatedOptOutEmbedCodeGenerator(
                translator: $application->make(MatomoTranslator::class),
                trustedHosts: $application->make(InstallationConfig::class)->trustedHosts(),
                trustedHostCheckEnabled: $application->make(InstallationConfig::class)->trustedHostCheckEnabled(),
            ),
        );
        $this->app->singleton(
            BrandingManager::class,
            function (Application $application): BrandingManager {
                $installation = $application->make(InstallationConfig::class);
                $path = $application->make(Repository::class)->get('matomo.config_path');

                if (! is_string($path) || $path === '') {
                    throw new RuntimeException('The Matomo configuration path is invalid.');
                }

                $instancePath = $installation->instanceId() === ''
                    ? ''
                    : '/'.$installation->instanceId();
                $temporaryPath = trim($installation->temporaryPath(), '/');
                $userRoot = dirname($path, 2);

                return new FileBrandingManager(
                    options: $application->make(MutableOptionRepository::class),
                    files: $application->make(Filesystem::class),
                    events: $application->make(Dispatcher::class),
                    publicDirectory: base_path('../misc/user').$instancePath,
                    publicRelativeDirectory: 'misc/user'.$instancePath,
                    temporaryLogosDirectory: $userRoot.'/'.$temporaryPath.$instancePath.'/logos',
                );
            },
        );
        $this->app->singleton(
            TrackingFailureRepository::class,
            fn (Application $application): TrackingFailureRepository => new DatabaseTrackingFailureRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(
            UserChangeReadRepository::class,
            fn (Application $application): UserChangeReadRepository => new DatabaseUserChangeReadRepository(
                $application->make(MatomoDatabase::class)->connection(),
                $application->make(Dispatcher::class),
            ),
        );
        $this->app->singleton(TransitionsSettings::class, ConfiguredTransitionsSettings::class);
        $this->app->singleton(TransitionsPeriodPolicy::class, ConfiguredTransitionsPeriodPolicy::class);
        $this->app->singleton(
            AiProviderCentralConfiguration::class,
            function (Application $application): AiProviderCentralConfiguration {
                $installation = $application->make(InstallationConfig::class)->aiProviders();
                $configured = $application->make(Repository::class)->get('matomo.ai_providers', []);

                return new AiProviderCentralConfiguration(array_replace(
                    $installation,
                    is_array($configured) ? $configured : [],
                ));
            },
        );
        $this->app->singleton(
            AiProviderSettingsRepository::class,
            fn (Application $application): AiProviderSettingsRepository => new DatabaseAiProviderSettingsRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(
            AiProviderConnectionTester::class,
            function (Application $application): AiProviderConnectionTester {
                $installation = $application->make(InstallationConfig::class);

                return new HttpAiProviderConnectionTester(
                    http: $application->make(HttpFactory::class),
                    hosts: $application->make(EgressHostResolver::class),
                    trustedAwsHosts: new EgressHostResolver(
                        allowedPrivateRanges: $installation->allowedPrivateEgressRanges(),
                        blockedHosts: [],
                    ),
                    internetFeaturesEnabled: $installation->internetFeaturesEnabled(),
                    outboundProxyHost: $installation->outboundProxyHost(),
                    outboundProxyExcludedHosts: $installation->outboundProxyExcludedHosts(),
                );
            },
        );
        $this->app->singleton(AiProviderSettingsManager::class);

        $this->app->singleton(
            ApiAccessAuthorizer::class,
            function (Application $application): ApiAccessAuthorizer {
                $installation = $application->make(InstallationConfig::class);
                $connection = $application->make(MatomoDatabase::class)->connection();

                return new DatabaseApiAccessAuthorizer(
                    connection: $connection,
                    salt: $installation->salt(),
                    onlyAllowSecureTokens: $installation->onlyAllowSecureTokens(),
                    sessions: new DatabaseSessionAuthenticator(
                        connection: $connection,
                        salt: $installation->salt(),
                        sessionLifetime: $installation->sessionLifetime(),
                        idleTimeout: $installation->sessionIdleTimeout(),
                    ),
                    events: $application->make(Dispatcher::class),
                );
            },
        );

        $this->app->singleton(
            PasswordConfirmationVerifier::class,
            fn (Application $application): PasswordConfirmationVerifier => new DatabasePasswordConfirmationVerifier(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            SiteRepository::class,
            fn (Application $application): SiteRepository => new DatabaseSiteRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            DashboardRepository::class,
            fn (Application $application): DashboardRepository => new DatabaseDashboardRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(
            DashboardRecipientPolicy::class,
            fn (Application $application): DashboardRecipientPolicy => new DatabaseDashboardRecipientPolicy(
                connection: $application->make(MatomoDatabase::class)->connection(),
                authorizer: $application->make(ApiAccessAuthorizer::class),
            ),
        );
        $this->app->singleton(
            DashboardLayoutProvider::class,
            fn (Application $application): DashboardLayoutProvider => new ConfiguredDashboardLayoutProvider(
                dashboards: $application->make(DashboardRepository::class),
                plugins: $application->make(PluginState::class),
                authorizer: $application->make(ApiAccessAuthorizer::class),
                events: $application->make(Dispatcher::class),
                professionalServicesAdsEnabled: $application->make(InstallationConfig::class)
                    ->professionalServicesAdsEnabled(),
            ),
        );

        $this->app->singleton(
            ReportingPeriodFactory::class,
            CarbonReportingPeriodFactory::class,
        );

        $this->app->singleton(
            ReportingSettings::class,
            fn (Application $application): ReportingSettings => new ConfiguredReportingSettings(
                $application->make(InstallationConfig::class),
            ),
        );

        $this->app->singleton(
            SegmentHashResolver::class,
            fn (Application $application): SegmentHashResolver => new DatabaseSegmentHashResolver(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            VisitsSummaryArchiveRepository::class,
            fn (Application $application): VisitsSummaryArchiveRepository => new DatabaseVisitsSummaryArchiveRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            NumericArchiveRepository::class,
            fn (Application $application): NumericArchiveRepository => new DatabaseVisitsSummaryArchiveRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            BlobArchiveRepository::class,
            fn (Application $application): BlobArchiveRepository => new DatabaseBlobArchiveRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            BatchBlobArchiveRepository::class,
            fn (Application $application): BatchBlobArchiveRepository => new DatabaseBlobArchiveRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            BlobArchiveMetadataRepository::class,
            fn (Application $application): BlobArchiveMetadataRepository => new DatabaseBlobArchiveRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            HierarchicalBlobArchiveRepository::class,
            fn (Application $application): HierarchicalBlobArchiveRepository => new DatabaseBlobArchiveRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            ScreenResolutionPolicy::class,
            fn (Application $application): ScreenResolutionPolicy => new ConfiguredScreenResolutionPolicy(
                settings: $application->make(PolicySettingRepository::class),
                configuredCnilPolicy: $application->make(InstallationConfig::class)->configuredCnilPolicy(),
            ),
        );
        $this->app->singleton(
            DeviceModelPolicy::class,
            fn (Application $application): DeviceModelPolicy => new ConfiguredDeviceModelPolicy(
                settings: $application->make(PolicySettingRepository::class),
                configuredCnilPolicy: $application->make(InstallationConfig::class)->configuredCnilPolicy(),
            ),
        );
        $this->app->singleton(
            DeviceDetectionMetadata::class,
            fn (Application $application): DeviceDetectionMetadata => new DeviceDetectionMetadata(
                translator: $application->make(MatomoTranslator::class),
                rootPath: base_path('..'),
            ),
        );

        $this->app->singleton(
            EgressHostResolver::class,
            fn (Application $application): EgressHostResolver => new EgressHostResolver(
                $application->make(InstallationConfig::class)->allowedPrivateEgressRanges(),
            ),
        );

        $this->app->singleton(
            ConsentManagerDetector::class,
            function (Application $application): ConsentManagerDetector {
                $installation = $application->make(InstallationConfig::class);

                return new HttpConsentManagerDetector(
                    http: $application->make(HttpFactory::class),
                    cache: $application->make(CacheRepository::class),
                    logger: $application->make(LoggerInterface::class),
                    hosts: $application->make(EgressHostResolver::class),
                    internetFeaturesEnabled: $installation->internetFeaturesEnabled(),
                    outboundProxyHost: $installation->outboundProxyHost(),
                    outboundProxyExcludedHosts: $installation->outboundProxyExcludedHosts(),
                );
            },
        );

        $this->app->singleton(
            SiteRuntimeSettings::class,
            fn (Application $application): SiteRuntimeSettings => new ConfiguredSiteRuntimeSettings(
                $application->make(InstallationConfig::class),
            ),
        );

        $this->app->singleton(
            MatomoTranslator::class,
            fn (): MatomoTranslator => new JsonMatomoTranslator($this->translationDirectories()),
        );

        $this->app->singleton(
            CountryMetadataProvider::class,
            function (Application $application): CountryMetadataProvider {
                $countries = require base_path('../core/Intl/Data/Resources/countries.php');
                $isoRegions = require base_path('../plugins/GeoIp2/data/isoRegionNames.php');
                $legacyRegionMapping = require base_path('../plugins/GeoIp2/data/regionMapping.php');
                $GEOIP_REGION_NAME = [];
                require base_path('../libs/MaxMindGeoIP/geoipregionvars.php');

                return new LocalizedCountryMetadataProvider(
                    continentsByCountry: is_array($countries) ? $countries : [],
                    isoRegions: is_array($isoRegions) ? $isoRegions : [],
                    legacyRegions: $GEOIP_REGION_NAME,
                    legacyRegionMapping: is_array($legacyRegionMapping) ? $legacyRegionMapping : [],
                    flagDirectory: base_path('../plugins/Morpheus/icons/dist/flags'),
                    translator: $application->make(MatomoTranslator::class),
                );
            },
        );

        $this->app->singleton(
            DatabaseLanguagePreferenceRepository::class,
            fn (Application $application): DatabaseLanguagePreferenceRepository => new DatabaseLanguagePreferenceRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->alias(DatabaseLanguagePreferenceRepository::class, LanguagePreferenceRepository::class);
        $this->app->alias(
            DatabaseLanguagePreferenceRepository::class,
            MutableLanguagePreferenceRepository::class,
        );
        $this->app->singleton(
            LanguageCatalog::class,
            function (Application $application): LanguageCatalog {
                $installation = $application->make(InstallationConfig::class);

                return new FilesystemLanguageCatalog(
                    rootDirectory: base_path('..'),
                    configuredLanguages: $installation->availableLanguages()
                        ?? $this->defaultAvailableLanguages(),
                    activatedPlugins: $installation->activatedPlugins(),
                    bundledPlugins: $this->defaultBundledPlugins(),
                    developmentEnabled: $installation->developmentModeEnabled(),
                    events: $application->make(Dispatcher::class),
                );
            },
        );

        $this->app->singleton(
            LanguageResolver::class,
            function (Application $application): LanguageResolver {
                $installation = $application->make(InstallationConfig::class);

                return new ApiLanguageResolver(
                    authorizer: $application->make(ApiAccessAuthorizer::class),
                    preferences: $application->make(LanguagePreferenceRepository::class),
                    availableLanguages: $this->availableLanguages($installation),
                    defaultLanguage: $installation->defaultLanguage(),
                    cookieName: $installation->languageCookieName(),
                    salt: $installation->salt(),
                );
            },
        );

        $this->app->singleton(
            CurrencyProvider::class,
            function (Application $application): CurrencyProvider {
                $currencies = require base_path('../core/Intl/Data/Resources/currencies.php');

                return new ConfiguredCurrencyProvider(
                    currencies: is_array($currencies) ? $currencies : [],
                    customCurrencies: $application->make(InstallationConfig::class)->customCurrencies(),
                    translator: $application->make(MatomoTranslator::class),
                );
            },
        );

        $this->app->singleton(
            TimezoneProvider::class,
            function (Application $application): TimezoneProvider {
                $countries = require base_path('../core/Intl/Data/Resources/countries.php');

                return new LocalizedTimezoneProvider(
                    translator: $application->make(MatomoTranslator::class),
                    countries: is_array($countries) ? $countries : [],
                );
            },
        );

        $this->app->singleton(
            SiteDetailsPresenter::class,
            fn (Application $application): SiteDetailsPresenter => new LocalizedSiteDetailsPresenter(
                timezones: $application->make(TimezoneProvider::class),
                translator: $application->make(MatomoTranslator::class),
            ),
        );

        $this->app->singleton(
            DatabaseOptionRepository::class,
            fn (Application $application): DatabaseOptionRepository => new DatabaseOptionRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(
            OptionRepository::class,
            fn (Application $application): OptionRepository => $application->make(DatabaseOptionRepository::class),
        );
        $this->app->singleton(
            MutableOptionRepository::class,
            fn (Application $application): MutableOptionRepository => $application->make(
                DatabaseOptionRepository::class,
            ),
        );
        $this->app->singleton(
            ArchiveInvalidationManager::class,
            function (Application $application): ArchiveInvalidationManager {
                $configuration = $application->make(InstallationConfig::class);

                return new DatabaseArchiveInvalidationManager(
                    connection: $application->make(MatomoDatabase::class)->connection(),
                    periods: $application->make(ReportingPeriodFactory::class),
                    segments: $application->make(SegmentHashResolver::class),
                    options: $application->make(MutableOptionRepository::class),
                    events: $application->make(Dispatcher::class),
                    enabledReportingPeriods: array_values(array_filter(
                        ['day', 'week', 'month', 'year', 'range'],
                        $configuration->reportingPeriodEnabled(...),
                    )),
                    configuredAutoArchiveSegments: $configuration->autoArchiveSegments(),
                );
            },
        );
        $this->app->singleton(
            ArchiveVisitQueryFactory::class,
            fn (Application $application): ArchiveVisitQueryFactory => new ArchiveVisitQueryFactory(
                connection: $application->make(MatomoDatabase::class)->connection(),
                segments: $application->make(VisitSegmentApplicator::class),
                events: $application->make(Dispatcher::class),
            ),
        );
        $this->app->singleton(
            ArchiveActionQueryFactory::class,
            fn (Application $application): ArchiveActionQueryFactory => new ArchiveActionQueryFactory(
                connection: $application->make(MatomoDatabase::class)->connection(),
                segments: $application->make(VisitSegmentApplicator::class),
                events: $application->make(Dispatcher::class),
            ),
        );
        $this->app->singleton(
            ArchiveConversionQueryFactory::class,
            fn (Application $application): ArchiveConversionQueryFactory => new ArchiveConversionQueryFactory(
                connection: $application->make(MatomoDatabase::class)->connection(),
                segments: $application->make(ConversionSegmentApplicator::class),
                events: $application->make(Dispatcher::class),
            ),
        );
        $this->app->singleton(
            BrowserLanguageArchiveLabeler::class,
            fn (Application $application): BrowserLanguageArchiveLabeler => new BrowserLanguageArchiveLabeler(
                languageCodes: $this->stringResourceKeys(
                    '../core/Intl/Data/Resources/languages.php',
                ),
                countryCodes: $application->make(CountryMetadataProvider::class)->codes(),
                countriesByLanguage: $this->stringResourceMap(
                    '../core/Intl/Data/Resources/languages-to-countries.php',
                ),
            ),
        );
        $this->app->singleton(
            VisitDimensionArchiveCollector::class,
            fn (Application $application): VisitDimensionArchiveCollector => new VisitDimensionArchiveCollector(
                connection: $application->make(MatomoDatabase::class)->connection(),
                visitQueries: $application->make(ArchiveVisitQueryFactory::class),
                conversionQueries: $application->make(ArchiveConversionQueryFactory::class),
                subperiods: $application->make(ReportingSubperiodFactory::class),
                segments: $application->make(SegmentHashResolver::class),
                blobs: $application->make(BlobArchiveRepository::class),
                sites: $application->make(SiteRepository::class),
                browserLanguages: $application->make(BrowserLanguageArchiveLabeler::class),
            ),
        );
        $this->app->singleton(
            VisitAggregateArchiveCollector::class,
            fn (Application $application): VisitAggregateArchiveCollector => new VisitAggregateArchiveCollector(
                connection: $application->make(MatomoDatabase::class)->connection(),
                visitQueries: $application->make(ArchiveVisitQueryFactory::class),
                subperiods: $application->make(ReportingSubperiodFactory::class),
                segments: $application->make(SegmentHashResolver::class),
                blobs: $application->make(BlobArchiveRepository::class),
                sites: $application->make(SiteRepository::class),
            ),
        );
        $this->app->singleton(
            GoalArchiveCollector::class,
            fn (Application $application): GoalArchiveCollector => new GoalArchiveCollector(
                connection: $application->make(MatomoDatabase::class)->connection(),
                conversionQueries: $application->make(ArchiveConversionQueryFactory::class),
                subperiods: $application->make(ReportingSubperiodFactory::class),
                segments: $application->make(SegmentHashResolver::class),
                blobs: $application->make(BatchBlobArchiveRepository::class),
                numbers: $application->make(NumericArchiveRepository::class),
                goals: $application->make(GoalRepository::class),
                sites: $application->make(SiteRepository::class),
            ),
        );
        $this->app->singleton(
            EcommerceItemArchiveCollector::class,
            fn (Application $application): EcommerceItemArchiveCollector => new EcommerceItemArchiveCollector(
                connection: $application->make(MatomoDatabase::class)->connection(),
                actionQueries: $application->make(ArchiveActionQueryFactory::class),
                subperiods: $application->make(ReportingSubperiodFactory::class),
                segments: $application->make(SegmentHashResolver::class),
                blobs: $application->make(BatchBlobArchiveRepository::class),
                sites: $application->make(SiteRepository::class),
            ),
        );
        $this->app->singleton(
            EventArchiveCollector::class,
            fn (Application $application): EventArchiveCollector => new EventArchiveCollector(
                connection: $application->make(MatomoDatabase::class)->connection(),
                actionQueries: $application->make(ArchiveActionQueryFactory::class),
                subperiods: $application->make(ReportingSubperiodFactory::class),
                segments: $application->make(SegmentHashResolver::class),
                blobs: $application->make(HierarchicalBlobArchiveRepository::class),
                sites: $application->make(SiteRepository::class),
            ),
        );
        $this->app->singleton(
            ContentArchiveCollector::class,
            fn (Application $application): ContentArchiveCollector => new ContentArchiveCollector(
                connection: $application->make(MatomoDatabase::class)->connection(),
                actionQueries: $application->make(ArchiveActionQueryFactory::class),
                subperiods: $application->make(ReportingSubperiodFactory::class),
                segments: $application->make(SegmentHashResolver::class),
                blobs: $application->make(HierarchicalBlobArchiveRepository::class),
                sites: $application->make(SiteRepository::class),
            ),
        );
        $this->app->singleton(
            ReportArchiver::class,
            fn (Application $application): ReportArchiver => new DatabaseReportArchiver(
                connection: $application->make(MatomoDatabase::class)->connection(),
                periods: $application->make(ReportingPeriodFactory::class),
                subperiods: $application->make(ReportingSubperiodFactory::class),
                segments: $application->make(SegmentHashResolver::class),
                sites: $application->make(SiteRepository::class),
                options: $application->make(OptionRepository::class),
                segmentValidator: $application->make(SegmentDefinitionValidator::class),
                visitQueries: $application->make(ArchiveVisitQueryFactory::class),
                events: $application->make(Dispatcher::class),
            ),
        );
        $this->app->singleton(VisitSegmentApplicator::class, BuiltInVisitSegmentApplicator::class);
        $this->app->singleton(ConversionSegmentApplicator::class, BuiltInVisitSegmentApplicator::class);
        $this->app->singleton(
            ReportingSubperiodFactory::class,
            CarbonReportingSubperiodFactory::class,
        );
        $this->app->singleton(
            ScheduledTaskLock::class,
            fn (Application $application): ScheduledTaskLock => new DatabaseScheduledTaskLock(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(
            ScheduledTaskRunner::class,
            fn (Application $application): ScheduledTaskRunner => new DatabaseScheduledTaskRunner(
                options: $application->make(MutableOptionRepository::class),
                locks: $application->make(ScheduledTaskLock::class),
                events: $application->make(Dispatcher::class),
                logger: $application->make(LoggerInterface::class),
            ),
        );

        $this->app->singleton(
            PolicySettingRepository::class,
            fn (Application $application): PolicySettingRepository => new DatabasePolicySettingRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            QueryParameterExclusionPolicy::class,
            function (Application $application): QueryParameterExclusionPolicy {
                $installation = $application->make(InstallationConfig::class);

                return new ConfiguredQueryParameterExclusionPolicy(
                    options: $application->make(OptionRepository::class),
                    settings: $application->make(PolicySettingRepository::class),
                    configuredCnilPolicy: $installation->configuredCnilPolicy(),
                    configuredFilterPiiEnforcement: $installation->configuredFilterPiiEnforcement(),
                    recommendedPiiParameters: $installation->commonPiiParameters()
                        ?? $this->defaultCommonPiiParameters(),
                );
            },
        );

        $this->app->singleton(
            PluginState::class,
            fn (Application $application): PluginState => new ConfiguredPluginState(
                $application->make(InstallationConfig::class),
            ),
        );

        $this->app->singleton(GeolocationSettings::class, ConfiguredGeolocationSettings::class);
        $this->app->singleton(
            TrackerCacheInvalidator::class,
            fn (): TrackerCacheInvalidator => new FileTrackerCacheInvalidator(
                base_path('../tmp/cache/tracker/matomocache_general.php'),
            ),
        );
        $this->app->singleton(
            ServerVariableMapping::class,
            fn (Application $application): ServerVariableMapping => new DatabaseServerVariableMapping(
                connection: $application->make(MatomoDatabase::class)->connection(),
                defaults: [
                    'continent_code' => 'MM_CONTINENT_CODE',
                    'continent_name' => 'MM_CONTINENT_NAME',
                    'country_code' => 'MM_COUNTRY_CODE',
                    'country_name' => 'MM_COUNTRY_NAME',
                    'region_code' => 'MM_REGION_CODE',
                    'region_name' => 'MM_REGION_NAME',
                    'lat' => 'MM_LATITUDE',
                    'long' => 'MM_LONGITUDE',
                    'postal_code' => 'MM_POSTAL_CODE',
                    'city_name' => 'MM_CITY_NAME',
                    'isp' => 'MM_ISP',
                    'org' => 'MM_ORG',
                ],
            ),
        );
        $this->app->singleton(
            GeolocationProviderRegistry::class,
            function (Application $application): GeolocationProviderRegistry {
                $configuration = $application->make(InstallationConfig::class);
                $options = $application->make(MutableOptionRepository::class);
                $countries = $application->make(CountryMetadataProvider::class);
                $languagesToCountries = require base_path(
                    '../core/Intl/Data/Resources/languages-to-countries.php',
                );
                $maxMind = new MaxMindDatabaseGeolocationProvider(
                    databaseDirectory: base_path('../misc'),
                    ispEnabled: true,
                    options: $options,
                    countries: $countries,
                );
                $providers = [
                    new DisabledGeolocationProvider,
                    new LanguageGeolocationProvider(
                        enabled: $configuration->defaultLocationProviderEnabled(),
                        guessCountryFromLanguage: $configuration->languageToCountryGuessEnabled(),
                        countryCodes: $countries->codes(),
                        countriesByLanguage: is_array($languagesToCountries) ? $languagesToCountries : [],
                    ),
                ];

                if ($application->make(PluginState::class)->isActivated('GeoIp2')) {
                    $providers[] = $maxMind;
                    $providers[] = new ServerModuleGeolocationProvider(
                        variables: $application->make(ServerVariableMapping::class),
                        fallback: $maxMind,
                        options: $options,
                    );
                }

                return new ConfiguredGeolocationProviderRegistry(
                    providers: $providers,
                    options: $options,
                    trackerCache: $application->make(TrackerCacheInvalidator::class),
                    defaultProviderId: $configuration->defaultLocationProviderEnabled()
                        ? 'default'
                        : 'disabled',
                );
            },
        );

        $this->app->singleton(
            TrackerFileAvailability::class,
            fn (): TrackerFileAvailability => new LocalTrackerFileAvailability(
                base_path('../js/piwik.min.js'),
                base_path('../matomo.js'),
            ),
        );

        $this->app->singleton(
            PromoWidgetDismissalRepository::class,
            fn (Application $application): PromoWidgetDismissalRepository => new DatabasePromoWidgetDismissalRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            FeedbackStore::class,
            fn (Application $application): FeedbackStore => new DatabaseFeedbackStore(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(FeedbackSettings::class, ConfiguredFeedbackSettings::class);
        $this->app->singleton(
            FeedbackFeatureNameResolver::class,
            fn (Application $application): FeedbackFeatureNameResolver => new JsonFeedbackFeatureNameResolver(
                translationDirectories: $this->translationDirectories(),
                translator: $application->make(MatomoTranslator::class),
            ),
        );
        $this->app->singleton(
            FeedbackMailer::class,
            function (Application $application): FeedbackMailer {
                $installation = $application->make(InstallationConfig::class);

                return new LaravelFeedbackMailer(
                    mailer: $application->make(Mailer::class),
                    enabled: $installation->emailsEnabled(),
                    fromAddress: $installation->noReplyEmailAddress(),
                    fromName: $installation->noReplyEmailName(),
                );
            },
        );
        $this->app->singleton(
            GoalRepository::class,
            fn (Application $application): GoalRepository => new DatabaseGoalRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(
            SiteTrackerCacheInvalidator::class,
            fn (): SiteTrackerCacheInvalidator => new FileSiteTrackerCacheInvalidator(
                base_path('../tmp/cache/tracker'),
            ),
        );

        $this->app->singleton(
            BruteForceSettings::class,
            function (Application $application): BruteForceSettings {
                $installation = $application->make(InstallationConfig::class);

                return new DatabaseBruteForceSettings(
                    connection: $application->make(MatomoDatabase::class)->connection(),
                    configuredMaxAttempts: $installation->configuredLoginMaxAllowedRetries(),
                    configuredTimeRange: $installation->configuredLoginAllowedRetriesTimeRange(),
                    configuredAllowlist: $installation->configuredLoginBruteForceAllowlist(),
                );
            },
        );
        $this->app->singleton(
            BruteForceUnblocker::class,
            fn (Application $application): BruteForceUnblocker => new DatabaseBruteForceUnblocker(
                connection: $application->make(MatomoDatabase::class)->connection(),
                settings: $application->make(BruteForceSettings::class),
            ),
        );
        $this->app->singleton(
            LoginAttemptGuard::class,
            fn (Application $application): LoginAttemptGuard => new DatabaseLoginAttemptGuard(
                connection: $application->make(MatomoDatabase::class)->connection(),
                settings: $application->make(BruteForceSettings::class),
            ),
        );

        $this->app->singleton(
            TourDataRepository::class,
            fn (Application $application): TourDataRepository => new DatabaseTourDataRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );
        $this->app->singleton(TourSettings::class, ConfiguredTourSettings::class);
        $this->app->singleton(
            TwoFactorAuthenticationResetter::class,
            fn (Application $application): TwoFactorAuthenticationResetter => new DatabaseTwoFactorAuthenticationResetter(
                connection: $application->make(MatomoDatabase::class)->connection(),
                events: $application->make(Dispatcher::class),
            ),
        );

        $this->app->singleton(
            ClientIpResolver::class,
            function (Application $application): ClientIpResolver {
                $installation = $application->make(InstallationConfig::class);

                return new ClientIpResolver(
                    proxyHeaders: $installation->proxyClientHeaders(),
                    proxyIps: $installation->proxyIps(),
                    readLastProxyIp: $installation->proxyIpReadLastInList(),
                );
            },
        );

        $this->app->singleton(
            ReportingApiIpAllowlist::class,
            function (Application $application): ReportingApiIpAllowlist {
                $installation = $application->make(InstallationConfig::class);

                return new ConfiguredReportingApiIpAllowlist(
                    clientIps: $application->make(ClientIpResolver::class),
                    cache: $application->make(CacheRepository::class),
                    allowlistedIps: $installation->loginAllowlistIps(),
                    appliesToReportingApi: $installation->loginAllowlistAppliesToReportingApi(),
                );
            },
        );

        $this->app->singleton(
            ApiMethodDispatcher::class,
            fn (Application $application): ApiMethodDispatcher => new ApiMethodDispatcher([
                $application->make(CoreApiMethodHandler::class),
                $application->make(CoreAdminHomeApiMethodHandler::class),
                $application->make(SitesManagerApiMethodHandler::class),
                $application->make(VisitsSummaryApiMethodHandler::class),
                $application->make(VisitFrequencyApiMethodHandler::class),
                $application->make(VisitTimeApiMethodHandler::class),
                $application->make(VisitorInterestApiMethodHandler::class),
                $application->make(UserLanguageApiMethodHandler::class),
                $application->make(ResolutionApiMethodHandler::class),
                $application->make(DevicePluginsApiMethodHandler::class),
                $application->make(DevicesDetectionApiMethodHandler::class),
                $application->make(EventsApiMethodHandler::class),
                $application->make(ExampleApiMethodHandler::class),
                $application->make(ExamplePluginApiMethodHandler::class),
                $application->make(ExampleReportApiMethodHandler::class),
                $application->make(ExampleUiApiMethodHandler::class),
                $application->make(FeedbackApiMethodHandler::class),
                $application->make(GoalsApiMethodHandler::class),
                $application->make(GoalsReportApiMethodHandler::class),
                $application->make(JsTrackerInstallCheckApiMethodHandler::class),
                $application->make(LanguagesManagerApiMethodHandler::class),
                $application->make(OverlayApiMethodHandler::class),
                $application->make(PagePerformanceApiMethodHandler::class),
                $application->make(UserIdApiMethodHandler::class),
                $application->make(ContentsApiMethodHandler::class),
                $application->make(CustomJsTrackerApiMethodHandler::class),
                $application->make(DashboardApiMethodHandler::class),
                $application->make(ProfessionalServicesApiMethodHandler::class),
                $application->make(LoginApiMethodHandler::class),
                $application->make(AiAgentsApiMethodHandler::class),
                $application->make(AiProvidersApiMethodHandler::class),
                $application->make(TourApiMethodHandler::class),
                $application->make(TransitionsApiMethodHandler::class),
                $application->make(TwoFactorAuthApiMethodHandler::class),
                $application->make(UserCountryApiMethodHandler::class),
            ]),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Dispatcher $events): void
    {
        $events->listen(ArchiveReportsCollecting::class, VisitDimensionArchiveCollector::class);
        $events->listen(ArchiveReportsCollecting::class, VisitAggregateArchiveCollector::class);
        $events->listen(ArchiveReportsCollecting::class, GoalArchiveCollector::class);
        $events->listen(ArchiveReportsCollecting::class, EcommerceItemArchiveCollector::class);
        $events->listen(ArchiveReportsCollecting::class, EventArchiveCollector::class);
        $events->listen(ArchiveReportsCollecting::class, ContentArchiveCollector::class);
    }

    /**
     * @return list<string>
     */
    private function defaultCommonPiiParameters(): array
    {
        $configuration = parse_ini_file(
            base_path('../config/global.ini.php'),
            true,
            INI_SCANNER_RAW,
        );

        if (! is_array($configuration)
            || ! is_array($configuration['SitesManager'] ?? null)
            || ! is_array($configuration['SitesManager']['CommonPIIParams'] ?? null)) {
            throw new RuntimeException('The Matomo recommended PII parameter list is invalid.');
        }

        return array_values(array_filter(
            $configuration['SitesManager']['CommonPIIParams'],
            is_string(...),
        ));
    }

    /** @return list<string> */
    private function stringResourceKeys(string $relativePath): array
    {
        $values = require base_path($relativePath);

        return is_array($values)
            ? array_values(array_filter(array_keys($values), is_string(...)))
            : [];
    }

    /** @return array<string, string> */
    private function stringResourceMap(string $relativePath): array
    {
        $values = require base_path($relativePath);
        $result = [];

        if (! is_array($values)) {
            return $result;
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function availableLanguages(InstallationConfig $installation): array
    {
        $configured = $installation->availableLanguages() ?? $this->defaultAvailableLanguages();
        $paths = glob(base_path('../lang/*.json'));

        if (! is_array($paths)) {
            throw new RuntimeException('The Matomo language directory is not readable.');
        }

        $installed = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );

        return array_values(array_intersect($installed, $configured));
    }

    /**
     * @return list<string>
     */
    private function defaultAvailableLanguages(): array
    {
        $configuration = parse_ini_file(
            base_path('../config/global.ini.php'),
            true,
            INI_SCANNER_RAW,
        );

        if (! is_array($configuration)
            || ! is_array($configuration['Languages'] ?? null)
            || ! is_array($configuration['Languages']['Languages'] ?? null)) {
            throw new RuntimeException('The Matomo language list is invalid.');
        }

        return array_values(array_filter(
            $configuration['Languages']['Languages'],
            is_string(...),
        ));
    }

    /** @return list<string> */
    private function defaultBundledPlugins(): array
    {
        $configuration = parse_ini_file(
            base_path('../config/global.ini.php'),
            true,
            INI_SCANNER_RAW,
        );

        if (! is_array($configuration)
            || ! is_array($configuration['Plugins'] ?? null)
            || ! is_array($configuration['Plugins']['Plugins'] ?? null)) {
            throw new RuntimeException('The Matomo bundled plugin list is invalid.');
        }

        $enabled = array_values(array_filter(
            $configuration['Plugins']['Plugins'],
            is_string(...),
        ));

        return array_values(array_unique([
            ...$enabled,
            'ArchivingMetrics',
            'DBStats',
            'ExamplePlugin',
            'ExampleCommand',
            'ExampleSettingsPlugin',
            'ExampleUI',
            'ExampleVisualization',
            'ExamplePluginTemplate',
            'ExampleTracker',
            'ExampleLogTables',
            'ExampleReport',
            'ExampleAPI',
            'ExampleVue',
            'MobileAppMeasurable',
            'TagManager',
            'ExampleTheme',
        ]));
    }

    /** @return list<string> */
    private function translationDirectories(): array
    {
        $directories = [base_path('../lang')];
        $pluginDirectories = glob(base_path('../plugins/*/lang'), GLOB_ONLYDIR);

        if (is_array($pluginDirectories)) {
            sort($pluginDirectories);
            $directories = [...$directories, ...$pluginDirectories];
        }

        return $directories;
    }
}
