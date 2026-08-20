<?php

declare(strict_types=1);

namespace Tests;

use App\Matomo\AiProviders\AiProviderCentralConfiguration;
use App\Matomo\AiProviders\AiProviderConfiguration;
use App\Matomo\AiProviders\AiProviderConnectionTester;
use App\Matomo\AiProviders\AiProviderDefinition;
use App\Matomo\AiProviders\AiProviderSettingsRepository;
use App\Matomo\AiProviders\AiProviderStoredSettings;
use App\Matomo\Annotations\AnnotationRepository;
use App\Matomo\Api\GoalDefinition;
use App\Matomo\Api\OptOutEmbedRequest;
use App\Matomo\Archiving\ArchiveInvalidationManager;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\ArchiveReportResult;
use App\Matomo\Archiving\CronArchiveRunner;
use App\Matomo\Archiving\CronArchiveRunResult;
use App\Matomo\Archiving\ReportArchiver;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\BotTracking\BotTrackingRealtimeRepository;
use App\Matomo\CoreAdmin\BrandingManager;
use App\Matomo\CoreAdmin\CoreAdminSettings;
use App\Matomo\CoreAdmin\OptOutEmbedCodeGenerator;
use App\Matomo\Dashboard\DashboardLayoutProvider;
use App\Matomo\Dashboard\DashboardRecipientPolicy;
use App\Matomo\Dashboard\DashboardRepository;
use App\Matomo\Feedback\FeedbackFeatureNameResolver;
use App\Matomo\Feedback\FeedbackMailer;
use App\Matomo\Feedback\FeedbackSettings;
use App\Matomo\Feedback\FeedbackStore;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Geolocation\GeolocationProviderRegistry;
use App\Matomo\Geolocation\GeolocationSettings;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Live\LiveAccessPolicy;
use App\Matomo\Live\LiveCounterRepository;
use App\Matomo\Localization\LanguageCatalog;
use App\Matomo\Localization\LanguagePreferenceRepository;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MutableLanguagePreferenceRepository;
use App\Matomo\Login\BruteForceUnblocker;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\LoginAttemptStatus;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Privacy\AnonymisationSettingsRepository;
use App\Matomo\Privacy\AnonymizableColumnProvider;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Privacy\ComplianceStatusProvider;
use App\Matomo\Privacy\DataPurger;
use App\Matomo\Privacy\DataSubjectFinder;
use App\Matomo\Privacy\DataSubjectRepository;
use App\Matomo\Privacy\DeletionBatchLimits;
use App\Matomo\Privacy\GranularComplianceSettingsProvider;
use App\Matomo\Privacy\PrivacyFeatureFlags;
use App\Matomo\Privacy\RawAnonymisationScheduler;
use App\Matomo\ProfessionalServices\PromoWidgetDismissalRepository;
use App\Matomo\Reporting\BlobArchiveMetadataRepository;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\DeviceModelPolicy;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\ScreenResolutionPolicy;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Scheduling\ScheduledTaskRunner;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ReportingApiIpAllowlist;
use App\Matomo\Sites\ConsentManagerDetector;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\MutableSiteRepository;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteDetailsPresenter;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Sites\SiteSettingsProvider;
use App\Matomo\Sites\TimezoneProvider;
use App\Matomo\Tour\TourDataRepository;
use App\Matomo\Tour\TourSettings;
use App\Matomo\TrackingFailures\TrackingFailureRepository;
use App\Matomo\Transitions\TransitionsPeriodPolicy;
use App\Matomo\TwoFactorAuth\TwoFactorAuthenticationResetter;
use App\Matomo\UserChanges\UserChangeReadRepository;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\NewsletterSubscriber;
use App\Matomo\Users\UserInvitationLinkFactory;
use App\Matomo\Users\UserInvitationNotifier;
use App\Matomo\Users\UserPreferenceDefaults;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SiteSettingsProvider::class, new class implements SiteSettingsProvider
        {
            public function metadata(int $siteId, string $language): array
            {
                return [];
            }
        });
        $this->app->instance(MutableSiteRepository::class, $this->createStub(MutableSiteRepository::class));
        $this->app->instance(UserPreferenceDefaults::class, new class implements UserPreferenceDefaults
        {
            public function reportDate(): string
            {
                return 'yesterday';
            }
        });
        $this->app->instance(DeletionBatchLimits::class, new class implements DeletionBatchLimits
        {
            public function logs(): int
            {
                return 100_000;
            }

            public function unusedActions(): int
            {
                return 100_000;
            }
        });

        $this->app->instance(FeedbackStore::class, new class implements FeedbackStore
        {
            public function emailForLogin(string $login): string
            {
                return '';
            }

            public function setNextReminder(string $login, string $date): void {}
        });
        $this->app->instance(AnnotationRepository::class, new class implements AnnotationRepository
        {
            public function create(
                int $siteId,
                string $date,
                string $note,
                bool $starred,
                string $login,
            ): array {
                return [
                    'id' => 1,
                    'idsite' => $siteId,
                    'date' => $date,
                    'note' => $note,
                    'starred' => (int) $starred,
                    'user' => $login,
                ];
            }

            public function find(int $siteId, int $noteId): ?array
            {
                return null;
            }

            public function update(int $siteId, int $noteId, array $values): ?array
            {
                return null;
            }

            public function delete(int $siteId, int $noteId): void {}

            public function deleteAll(int $siteId): void {}

            public function forSite(
                int $siteId,
                ?string $startDate,
                ?string $endDate,
                ?int $limit = null,
            ): array {
                return [];
            }
        });
        $this->app->instance(FeedbackMailer::class, new class implements FeedbackMailer
        {
            public function send(
                string $recipient,
                string $replyTo,
                string $subject,
                string $body,
                string $host,
            ): void {}
        });
        $this->app->instance(
            FeedbackFeatureNameResolver::class,
            new class implements FeedbackFeatureNameResolver
            {
                public function englishName(string $name, string $language): string
                {
                    return $name;
                }
            },
        );
        $this->app->instance(FeedbackSettings::class, new class implements FeedbackSettings
        {
            public function recipient(): string
            {
                return 'feedback@matomo.org';
            }
        });
        $this->app->instance(GoalRepository::class, new class implements GoalRepository
        {
            public function findActive(int $siteId, int $goalId): ?array
            {
                return null;
            }

            public function activeForSites(array $siteIds): array
            {
                return [];
            }

            public function create(int $siteId, GoalDefinition $goal): int
            {
                return 1;
            }

            public function update(int $siteId, int $goalId, GoalDefinition $goal): void {}

            public function delete(int $siteId, int $goalId): void {}
        });
        $this->app->instance(
            SiteTrackerCacheInvalidator::class,
            new class implements SiteTrackerCacheInvalidator
            {
                public function clear(int $siteId): void {}
            },
        );

        $this->app->instance(AiProviderCentralConfiguration::class, new AiProviderCentralConfiguration);
        $this->app->instance(CoreAdminSettings::class, new class implements CoreAdminSettings
        {
            public function generalSettingsAdminEnabled(): bool
            {
                return true;
            }

            public function configureArchiving(bool $browserTriggerEnabled, int $todayTimeToLive): void {}

            public function replaceTrustedHosts(array $hosts): void {}
        });
        $this->app->instance(BrandingManager::class, new class implements BrandingManager
        {
            public function update(
                string $login,
                bool $useCustomLogo,
                bool $hasCustomLogo,
                bool $hasCustomFavicon,
            ): array {
                return ['useCustomLogo' => false];
            }
        });
        $this->app->instance(OptOutEmbedCodeGenerator::class, new class implements OptOutEmbedCodeGenerator
        {
            public function javascript(OptOutEmbedRequest $options): string
            {
                return '<script src="opt-out.js"></script>';
            }

            public function selfContained(OptOutEmbedRequest $options, string $language): string
            {
                return '<script>optOut()</script>';
            }
        });
        $this->app->instance(ArchiveInvalidationManager::class, new class implements ArchiveInvalidationManager
        {
            public function invalidate(
                array $siteIds,
                array $dates,
                ?string $period,
                ?string $segment,
                bool $cascadeDown,
                bool $forceInvalidateNonexistent,
            ): array {
                return [];
            }
        });
        $this->app->instance(ReportArchiver::class, new class implements ReportArchiver
        {
            public function archive(ArchiveReportRequest $request): ArchiveReportResult
            {
                return new ArchiveReportResult([], 0, false);
            }
        });
        $this->app->instance(CronArchiveRunner::class, new class implements CronArchiveRunner
        {
            public function run(): CronArchiveRunResult
            {
                return new CronArchiveRunResult([], 0, 0);
            }
        });
        $this->app->instance(ScheduledTaskRunner::class, new class implements ScheduledTaskRunner
        {
            public function run(): array
            {
                return [];
            }
        });
        $this->app->instance(TrackingFailureRepository::class, new class implements TrackingFailureRepository
        {
            public function all(): array
            {
                return [];
            }

            public function forSites(array $siteIds): array
            {
                return [];
            }

            public function deleteAll(): void {}

            public function deleteForSites(array $siteIds): void {}

            public function delete(int $siteId, int|string $failureId): void {}
        });
        $this->app->instance(UserChangeReadRepository::class, new class implements UserChangeReadRepository
        {
            public function markAllRead(string $login): bool
            {
                return false;
            }
        });
        $this->app->instance(LanguageCatalog::class, new class implements LanguageCatalog
        {
            public function available(bool $ignoreConfig = false): array
            {
                return ['en'];
            }

            public function isAvailable(string $languageCode, bool $ignoreConfig = false): bool
            {
                return $languageCode === 'en';
            }

            public function names(bool $ignoreConfig = false): array
            {
                return [];
            }

            public function information(bool $excludeNonCorePlugins = true, bool $ignoreConfig = false): array
            {
                return [];
            }

            public function translations(string $languageCode): ?array
            {
                return null;
            }
        });
        $languagePreferences = new class implements MutableLanguagePreferenceRepository
        {
            /** @var array<string, string> */
            private array $languages = [];

            /** @var array<string, bool> */
            private array $clocks = [];

            public function forLogin(string $login): ?string
            {
                return $this->languages[$login] ?? null;
            }

            public function setLanguage(string $login, string $language): bool
            {
                $this->languages[$login] = $language;

                return true;
            }

            public function uses12HourClock(string $login): bool
            {
                return $this->clocks[$login] ?? false;
            }

            public function set12HourClock(string $login, bool $use12HourClock): bool
            {
                $this->clocks[$login] = $use12HourClock;

                return true;
            }
        };
        $this->app->instance(LanguagePreferenceRepository::class, $languagePreferences);
        $this->app->instance(MutableLanguagePreferenceRepository::class, $languagePreferences);
        $this->app->instance(TransitionsPeriodPolicy::class, new class implements TransitionsPeriodPolicy
        {
            public function isAllowed(int $siteId, string $period, string $date): bool
            {
                return true;
            }
        });
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
        $this->app->instance(MutableUserRepository::class, $this->createStub(MutableUserRepository::class));
        $this->app->instance(
            AnonymizableColumnProvider::class,
            $this->createStub(AnonymizableColumnProvider::class),
        );
        $this->app->instance(
            AnonymisationSettingsRepository::class,
            $this->createStub(AnonymisationSettingsRepository::class),
        );
        $this->app->instance(
            DataSubjectRepository::class,
            $this->createStub(DataSubjectRepository::class),
        );
        $this->app->instance(
            DataSubjectFinder::class,
            $this->createStub(DataSubjectFinder::class),
        );
        $this->app->instance(DataPurger::class, $this->createStub(DataPurger::class));
        $this->app->instance(LiveAccessPolicy::class, $this->createStub(LiveAccessPolicy::class));
        $this->app->instance(LiveCounterRepository::class, $this->createStub(LiveCounterRepository::class));
        $this->app->instance(
            CompliancePolicyStateRepository::class,
            $this->createStub(CompliancePolicyStateRepository::class),
        );
        $this->app->instance(ComplianceStatusProvider::class, $this->createStub(ComplianceStatusProvider::class));
        $this->app->instance(
            GranularComplianceSettingsProvider::class,
            $this->createStub(GranularComplianceSettingsProvider::class),
        );
        $this->app->instance(PrivacyFeatureFlags::class, $this->createStub(PrivacyFeatureFlags::class));
        $this->app->instance(
            RawAnonymisationScheduler::class,
            $this->createStub(RawAnonymisationScheduler::class),
        );
        $this->app->instance(NewsletterSubscriber::class, $this->createStub(NewsletterSubscriber::class));
        $this->app->instance(
            UserInvitationNotifier::class,
            $this->createStub(UserInvitationNotifier::class),
        );
        $this->app->instance(
            UserInvitationLinkFactory::class,
            $this->createStub(UserInvitationLinkFactory::class),
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
        $options = new class implements MutableOptionRepository
        {
            /** @var array<string, string> */
            private array $values = [];

            public function value(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, string $value, bool $autoload = false): void
            {
                $this->values[$name] = $value;
            }

            public function delete(string $name): void
            {
                unset($this->values[$name]);
            }
        };
        $this->app->instance(MutableOptionRepository::class, $options);
        $this->app->instance(OptionRepository::class, $options);
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
        $this->app->instance(BotTrackingRealtimeRepository::class, new class implements BotTrackingRealtimeRepository
        {
            public function chatbotActivity(array $siteIds, string $startDate, string $endDate): array
            {
                return [];
            }

            public function topPageUrls(array $siteIds, string $startDate, string $endDate): array
            {
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
        $this->app->instance(
            HierarchicalBlobArchiveRepository::class,
            new class implements HierarchicalBlobArchiveRepository
            {
                public function records(
                    array $siteIds,
                    array $periods,
                    string $segmentHash,
                    string $recordName,
                    bool $includeSubtables,
                ): array {
                    return [];
                }
            },
        );
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

            public function replaceAliasUrls(int $idSite, array $urls): array
            {
                return $urls;
            }

            public function renameGroup(string $oldGroupName, string $newGroupName): array
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

            public function administrationEnabled(): bool
            {
                return true;
            }
        });
    }
}
