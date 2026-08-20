<?php

declare(strict_types=1);

namespace Tests;

use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Reporting\ReportingSettings;
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
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ClientIpResolver::class, new ClientIpResolver([], [], true));
        $this->app->instance(LanguageResolver::class, new class implements LanguageResolver
        {
            public function resolve(Request $request, ApiAuthentication $authentication): string
            {
                return 'en';
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
