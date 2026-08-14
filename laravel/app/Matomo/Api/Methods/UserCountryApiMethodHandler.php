<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiMetricReport;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\RssReportRenderer;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\UserCountryReportBuilder;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class UserCountryApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private ReportingPeriodFactory $periods,
        private ReportingSettings $settings,
        private SegmentHashResolver $segments,
        private LanguageResolver $languages,
        private UserCountryReportBuilder $reports,
        private RssReportRenderer $rss,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isUserCountryRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UserCountry API handler does not support this request.');
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);

        if ($request->method === 'UserCountry.getCountryCodeMapping') {
            return $this->responses->row($request, $this->reports->countryCodeMapping($language));
        }

        $query = $request->visitsSummary
            ?? throw new LogicException('The UserCountry request was not parsed.');
        $siteIds = $query->allSites
            ? $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $request->restrictSitesToLogin,
            )
            : $query->siteIds;

        if ($siteIds === []) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access.",
                401,
            );
        }

        if (! $query->allSites) {
            foreach ($siteIds as $idSite) {
                if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
                    return $this->responses->error(
                        $request,
                        "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                        401,
                    );
                }
            }
        }

        if (! $this->settings->periodEnabled($query->period)) {
            return $this->responses->error($request, "The period '{$query->period}' is not enabled.", 400);
        }

        if ($query->segment !== null
            && ! $this->settings->anonymousSegmentsEnabled()
            && $this->authorizer->authenticatedLogin($request->authentication) === 'anonymous') {
            return $this->responses->error(
                $request,
                'The Super User has disabled the Segmentation feature.',
                401,
            );
        }

        if ($request->format === 'rss' && count($siteIds) !== 1) {
            return $this->responses->error(
                $request,
                "RSS feeds can be generated for one specific website &idSite=X.\n".
                    'Please specify only one idSite or consider using &format=XML instead.',
                200,
            );
        }

        $timezone = count($siteIds) === 1 ? ($this->sites->timezone($siteIds[0]) ?? 'UTC') : 'UTC';

        try {
            [$periods, $forceDateIndex] = $this->periods->make($query->period, $query->date, $timezone);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        $forceSiteIndex = $query->allSites || count($siteIds) > 1;
        $segmentHash = $this->segments->resolve($query->segment);

        if ($request->method === 'UserCountry.getNumberOfDistinctCountries') {
            $report = $this->reports->distinctCountries(
                $siteIds,
                $periods,
                $segmentHash,
                $forceSiteIndex,
                $forceDateIndex,
            );

            return $request->format === 'rss'
                ? $this->rssMetric($request, $query->period, $siteIds, $periods, $timezone, $report)
                : $this->responses->metricReport($request, $report);
        }

        $report = $this->reports->table(
            continents: $request->method === 'UserCountry.getContinent',
            siteIds: $siteIds,
            periods: $periods,
            segmentHash: $segmentHash,
            language: $language,
            showMetadata: $request->showMetadata,
            forceSiteIndex: $forceSiteIndex,
            forceDateIndex: $forceDateIndex,
        );

        return $request->format === 'rss'
            ? $this->rssTable($request, $query->period, $siteIds, $periods, $timezone, $report)
            : $this->responses->tableReport($request, $report);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    private function rssTable(
        ApiRequest $request,
        string $requestedPeriod,
        array $siteIds,
        array $periods,
        string $timezone,
        ApiTableReport $report,
    ): Response {
        return $this->rssResponse(
            $request,
            $siteIds,
            fn (string $siteName): string => $this->rss->table(
                $report,
                $periods,
                $siteIds[0],
                $requestedPeriod,
                $siteName,
                $timezone,
            ),
        );
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    private function rssMetric(
        ApiRequest $request,
        string $requestedPeriod,
        array $siteIds,
        array $periods,
        string $timezone,
        ApiMetricReport $report,
    ): Response {
        return $this->rssResponse(
            $request,
            $siteIds,
            fn (string $siteName): string => $this->rss->metric(
                $report,
                $periods,
                $siteIds[0],
                $requestedPeriod,
                $siteName,
                $timezone,
            ),
        );
    }

    /**
     * @param  list<int>  $siteIds
     * @param  callable(string): string  $render
     */
    private function rssResponse(ApiRequest $request, array $siteIds, callable $render): Response
    {
        $details = $this->sites->details($siteIds[0]);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';

        try {
            return $this->responses->rss($render($siteName));
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }
    }
}
