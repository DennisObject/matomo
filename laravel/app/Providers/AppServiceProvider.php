<?php

declare(strict_types=1);

namespace App\Providers;

use App\Matomo\Api\Methods\ApiMethodDispatcher;
use App\Matomo\Api\Methods\CoreApiMethodHandler;
use App\Matomo\Api\Methods\SitesManagerApiMethodHandler;
use App\Matomo\Api\Methods\VisitFrequencyApiMethodHandler;
use App\Matomo\Api\Methods\VisitsSummaryApiMethodHandler;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseSessionAuthenticator;
use App\Matomo\Config\InstallationConfig;
use App\Matomo\Database\MatomoDatabase;
use App\Matomo\Localization\ApiLanguageResolver;
use App\Matomo\Localization\DatabaseLanguagePreferenceRepository;
use App\Matomo\Localization\JsonMatomoTranslator;
use App\Matomo\Localization\LanguagePreferenceRepository;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Plugins\ConfiguredPluginState;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Reporting\CarbonReportingPeriodFactory;
use App\Matomo\Reporting\ConfiguredReportingSettings;
use App\Matomo\Reporting\DatabaseSegmentHashResolver;
use App\Matomo\Reporting\DatabaseVisitsSummaryArchiveRepository;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
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
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
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
            SiteRepository::class,
            fn (Application $application): SiteRepository => new DatabaseSiteRepository(
                $application->make(MatomoDatabase::class)->connection(),
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
            fn (): MatomoTranslator => new JsonMatomoTranslator([
                base_path('../lang'),
                base_path('../plugins/Intl/lang'),
                base_path('../plugins/SitesManager/lang'),
            ]),
        );

        $this->app->singleton(
            LanguagePreferenceRepository::class,
            fn (Application $application): LanguagePreferenceRepository => new DatabaseLanguagePreferenceRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
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
            OptionRepository::class,
            fn (Application $application): OptionRepository => new DatabaseOptionRepository(
                $application->make(MatomoDatabase::class)->connection(),
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
                $application->make(SitesManagerApiMethodHandler::class),
                $application->make(VisitsSummaryApiMethodHandler::class),
                $application->make(VisitFrequencyApiMethodHandler::class),
            ]),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}

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
}
