<?php

declare(strict_types=1);

namespace Tests;

use App\Matomo\AiProviders\AiProviderCentralConfiguration;
use App\Matomo\AiProviders\AiProviderConfiguration;
use App\Matomo\AiProviders\AiProviderConnectionTester;
use App\Matomo\AiProviders\AiProviderDefinition;
use App\Matomo\AiProviders\AiProviderSettingsRepository;
use App\Matomo\AiProviders\AiProviderStoredSettings;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Dashboard\DashboardLayoutProvider;
use App\Matomo\Dashboard\DashboardRecipientPolicy;
use App\Matomo\Dashboard\DashboardRepository;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Geolocation\GeolocationProviderRegistry;
use App\Matomo\Geolocation\GeolocationSettings;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Login\BruteForceUnblocker;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Plugins\PluginState;
use App\Matomo\ProfessionalServices\PromoWidgetDismissalRepository;
use App\Matomo\Reporting\BlobArchiveMetadataRepository;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\DeviceModelPolicy;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\ScreenResolutionPolicy;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ReportingApiIpAllowlist;
use App\Matomo\Sites\ConsentManagerDetector;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteDetailsPresenter;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Sites\TimezoneProvider;
use App\Matomo\Tour\TourDataRepository;
use App\Matomo\Tour\TourSettings;
use App\Matomo\TwoFactorAuth\TwoFactorAuthenticationResetter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AiProviderCentralConfiguration::class, new AiProviderCentralConfiguration);
        $this->app->instance(
            AiProviderSettingsRepository::class,
            new class implements AiProviderSettingsRepository
            {
                private AiProviderStoredSettings $settings;

                public function __construct()
                {
                    $this->settings = new AiProviderStoredSettings('', 'instant', []);
                }

                public function read(): AiProviderStoredSettings
                {
                    return $this->settings;
                }

                public function save(#[\SensitiveParameter] AiProviderStoredSettings $settings): void
                {
                    $this->settings = $settings;
                }
            },
        );
        $this->app->instance(
            AiProviderConnectionTester::class,
            new class implements AiProviderConnectionTester
            {
                public function test(
                    AiProviderDefinition $provider,
                    #[\SensitiveParameter]
                    AiProviderConfiguration $configuration,
                ): array {
                    return [];
                }
            },
        );

        $this->app->instance(ClientIpResolver::class, new ClientIpResolver([], [], true));
        $this->app->instance(LoginAttemptGuard::class, new class implements LoginAttemptGuard
        {
            public function status(string $ipAddress, string $login): LoginAttemptStatus
            {
                return LoginAttemptStatus::Allowed;
            }

            public function recordFailure(string $ipAddress, string $login): void {}
        });
        $this->app->instance(
            PasswordConfirmationVerifier::class,
            new class implements PasswordConfirmationVerifier
            {
                public function isCorrect(string $login, string $password): bool
                {
                    return false;
                }
            },
        );
        $this->app->instance(
            TwoFactorAuthenticationResetter::class,
            new class implements TwoFactorAuthenticationResetter
            {
                public function reset(string $login): void {}
            },
        );
        $this->app->instance(DashboardRepository::class, new class implements DashboardRepository
        {
            public function all(string $login): array
            {
                return [];
            }

            public function layout(string $login, int $dashboardId): ?string
            {
                return null;
            }

            public function create(string $login, string $name, string $layout): int
            {
                return 1;
            }

            public function delete(string $login, int $dashboardId): void {}

            public function updateLayout(string $login, int $dashboardId, string $layout): void {}
        });
        $this->app->instance(DashboardRecipientPolicy::class, new class implements DashboardRecipientPolicy
        {
            public function canCopyTo(ApiAuthentication $authentication, string $login): bool
            {
                return false;
            }
        });
        $this->app->instance(DashboardLayoutProvider::class, new class implements DashboardLayoutProvider
        {
            public function defaultLayout(ApiAuthentication $authentication): string
            {
                return '{"config":{"layout":"33-33-33"},"columns":[]}';
            }

            public function visibleWidgets(string $layout): array
            {
                return [];
            }
        });
        $this->app->instance(LanguageResolver::class, new class implements LanguageResolver
        {
            public function resolve(Request $request, ApiAuthentication $authentication): string
            {
                return 'en';
            }
        });
        $this->app->instance(CountryMetadataProvider::class, new class implements CountryMetadataProvider
        {
            public function codes(): array
            {
                return [];
            }

            public function continentCode(string $countryCode): string
            {
                return 'unk';
            }

            public function countryName(string $countryCode, string $language): string
            {
                return $countryCode;
            }

            public function continentName(string $continentCode, string $language): string
            {
                return $continentCode;
            }

            public function flag(string $countryCode): string
            {
                return 'plugins/Morpheus/icons/dist/flags/xx.png';
            }

            public function regionName(string $countryCode, string $regionCode, string $language): string
            {
                return $regionCode;
            }

            public function regionCodeForName(string $countryCode, string $regionName): string
            {
                return '';
            }

            public function convertLegacyRegion(string $countryCode, string $regionCode): array
            {
                return ['country' => $countryCode, 'region' => $regionCode];
            }
        });
        $this->app->instance(
            GeolocationProviderRegistry::class,
            new class implements GeolocationProviderRegistry
            {
                public function locate(
                    string $ipAddress,
                    string $browserLanguage,
                    string $currentIpAddress,
                    ?string $providerId = null,
                ): ?array {
                    return null;
                }

                public function setCurrent(string $providerId): void {}
            },
        );
        $this->app->instance(GeolocationSettings::class, new class implements GeolocationSettings
        {
            public function adminEnabled(): bool
            {
                return true;
            }
        });
        $this->app->instance(CurrencyProvider::class, new class implements CurrencyProvider
        {
            public function symbols(): array
            {
                return [];
            }

            public function names(string $language): array
            {
                return [];
            }
        });
        $this->app->instance(ConsentManagerDetector::class, new class implements ConsentManagerDetector
        {
            public function detect(string $url, int $timeout): ?array
            {
                return null;
            }
        });
        $this->app->instance(TimezoneProvider::class, new class implements TimezoneProvider
        {
            public function all(string $language, bool $timezoneSupportEnabled): array
            {
                return [];
            }

            public function name(
                string $timezone,
                string $language,
                ?string $countryCode = null,
                ?bool $multipleTimezonesInCountry = null,
            ): string {
                return $timezone;
            }
        });
        $this->app->instance(SiteDetailsPresenter::class, new class implements SiteDetailsPresenter
        {
            public function present(array $site, string $language, bool $includeCreator): array
            {
                return $site;
            }
        });
        $this->app->instance(QueryParameterExclusionPolicy::class, new class implements QueryParameterExclusionPolicy
        {
            public function type(?int $idSite = null): string
            {
                return 'common_session_parameters';
            }

            public function parameters(?int $idSite = null): string
            {
                return '';
            }
        });
        $this->app->instance(OptionRepository::class, new class implements OptionRepository
        {
            public function value(string $name): ?string
            {
                return null;
            }
        });
        $this->app->instance(PluginState::class, new class implements PluginState
        {
            public function isActivated(string $pluginName): bool
            {
                return false;
            }
        });
        $this->app->instance(
            PromoWidgetDismissalRepository::class,
            new class implements PromoWidgetDismissalRepository
            {
                public function dismiss(string $login, string $widgetName, int $timestamp): void {}
            },
        );
        $this->app->instance(BruteForceUnblocker::class, new class implements BruteForceUnblocker
        {
            public function unblockCurrentlyBlocked(): int
            {
                return 0;
            }
        });
        $this->app->instance(TourDataRepository::class, new class implements TourDataRepository
        {
            public function progress(string $login): array
            {
                return [];
            }

            public function skip(string $login, string $challengeId): void {}

            public function hasTrackedData(): bool
            {
                return false;
            }

            public function hasAddedWebsite(string $login): bool
            {
                return false;
            }

            public function hasAddedScheduledReport(string $login): bool
            {
                return false;
            }

            public function hasCustomizedDashboard(string $login): bool
            {
                return false;
            }

            public function hasAddedSegment(string $login): bool
            {
                return false;
            }

            public function usesTwoFactorAuthentication(string $login): bool
            {
                return false;
            }
        });
        $this->app->instance(TourSettings::class, new class implements TourSettings
        {
            public function usersAdminEnabled(): bool
            {
                return true;
            }

            public function sitesAdminEnabled(): bool
            {
                return true;
            }

            public function generalSettingsAdminEnabled(): bool
            {
                return true;
            }

            public function geolocationAdminEnabled(): bool
            {
                return true;
            }

            public function customLogoEnabled(): bool
            {
                return true;
            }

            public function browserArchivingTriggerEnabled(): bool
            {
                return true;
            }
        });
        $this->app->instance(ReportingSettings::class, new class implements ReportingSettings
        {
            public function periodEnabled(string $period): bool
            {
                return true;
            }

            public function uniqueVisitorsEnabled(string $period): bool
            {
                return in_array($period, ['day', 'week', 'month'], true);
            }

            public function anonymousSegmentsEnabled(): bool
            {
                return true;
            }
        });
        $this->app->instance(ScreenResolutionPolicy::class, new class implements ScreenResolutionPolicy
        {
            public function detectionDisabled(int $idSite): bool
            {
                return false;
            }
        });
        $this->app->instance(DeviceModelPolicy::class, new class implements DeviceModelPolicy
        {
            public function detectionDisabled(int $idSite): bool
            {
                return false;
            }
        });
        $this->app->instance(SegmentHashResolver::class, new class implements SegmentHashResolver
        {
            public function resolve(?string $segment): string
            {
                return $segment === null ? '' : md5(urldecode($segment));
            }
        });
        $this->app->instance(VisitsSummaryArchiveRepository::class, new class implements VisitsSummaryArchiveRepository
        {
            public function metrics(
                array $siteIds,
                array $periods,
                string $segmentHash,
                array $metrics,
            ): array {
                return [];
            }
        });
        $this->app->instance(NumericArchiveRepository::class, new class implements NumericArchiveRepository
        {
            public function pluginMetrics(
                array $siteIds,
                array $periods,
                string $segmentHash,
                array $metrics,
                string $pluginName,
            ): array {
                return [];
            }
        });
        $this->app->instance(BlobArchiveRepository::class, new class implements BlobArchiveRepository
        {
            public function rows(
                array $siteIds,
                array $periods,
                string $segmentHash,
                string $recordName,
            ): array {
                return [];
            }
        });
        $this->app->instance(BlobArchiveMetadataRepository::class, new class implements BlobArchiveMetadataRepository
        {
            public function archives(
                array $siteIds,
                array $periods,
                string $segmentHash,
                string $recordName,
            ): array {
                return [];
            }
        });
        $this->app->instance(ReportingApiIpAllowlist::class, new class implements ReportingApiIpAllowlist
        {
            public function deniedClientIp(Request $request): ?string
            {
                return null;
            }
        });
        $this->app->instance(SiteRepository::class, new class implements SiteRepository
        {
            public function allIds(): array
            {
                return [];
            }

            public function details(int $idSite): array
            {
                return [];
            }

            public function mainUrl(int $idSite): ?string
            {
                return null;
            }

            public function timezone(int $idSite): ?string
            {
                return null;
            }

            public function allDetails(): array
            {
                return [];
            }

            public function detailsForIds(
                array $idSites,
                ?string $pattern = null,
                ?int $limit = null,
                array $siteTypesToExclude = [],
            ): array {
                return [];
            }

            public function detailsInGroup(string $group): array
            {
                return [];
            }

            public function groups(): array
            {
                return [];
            }

            public function urls(int $idSite): array
            {
                return [];
            }

            public function aliasUrlsForIds(array $idSites): array
            {
                return [];
            }

            public function excludedReferrers(int $idSite): ?string
            {
                return null;
            }

            public function excludedParameters(int $idSite): ?string
            {
                return null;
            }

            public function timezones(): array
            {
                return [];
            }

            public function idsInTimezones(array $timezones): array
            {
                return [];
            }

            public function idsForUrls(array $urls, array $allowedSiteIds): array
            {
                return [];
            }
        });
        $this->app->instance(SiteRuntimeSettings::class, new class implements SiteRuntimeSettings
        {
            public function timezoneSupportEnabled(): bool
            {
                return true;
            }

            public function websitesCountToDisplay(): int
            {
                return 15;
            }
        });
    }
}
