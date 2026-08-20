<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiReport;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Api\VisitsSummaryRequest;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\BotTrackingRealtimeReportBuilder;
use App\Matomo\Reporting\BotTrackingReportBuilder;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\RssReportRenderer;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class BotTrackingApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private ReportingPeriodFactory $periods,
        private ReportingSettings $settings,
        private LanguageResolver $languages,
        private BotTrackingReportBuilder $reports,
        private BotTrackingRealtimeReportBuilder $realtimeReports,
        private RssReportRenderer $rss,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isBotTrackingArchiveRequest() || $request->isBotTrackingRealtimeRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The BotTracking API handler does not support this request.');
        }

        if ($request->isBotTrackingRealtimeRequest()) {
            return $this->handleRealtime($request);
        }

        $query = $request->visitsSummary
            ?? throw new LogicException('The BotTracking request was not parsed.');
        $siteIds = $this->authorizedSiteIds($request, $query->siteIds, $query->allSites);

        if ($siteIds instanceof Response) {
            return $siteIds;
        }

        if (! $this->settings->periodEnabled($query->period)) {
            return $this->responses->error($request, "The period '{$query->period}' is not enabled.", 400);
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
        $report = $request->method === 'BotTracking.get'
            ? $this->reports->buildOverview(
                siteIds: $siteIds,
                periods: $periods,
                requestedPeriod: $query->period,
                requestedColumns: $query->columns,
                showColumns: $query->showColumns,
                hideColumns: $query->hideColumns,
                forceSiteIndex: $forceSiteIndex,
                forceDateIndex: $forceDateIndex,
            )
            : $this->reports->buildTable(
                method: $request->method,
                secondaryDimension: $query->secondaryDimension,
                expanded: $query->expanded,
                flat: $query->flat,
                idSubtable: $query->idSubtable,
                siteIds: $siteIds,
                periods: $periods,
                language: $this->languages->resolve($httpRequest, $request->authentication),
                showMetadata: $request->showMetadata,
                forceSiteIndex: $forceSiteIndex,
                forceDateIndex: $forceDateIndex,
            );

        if ($request->format !== 'rss') {
            return $report instanceof ApiReport
                ? $this->responses->report($request, $report)
                : $this->responses->tableReport($request, $report);
        }

        return $this->rssResponse($request, $query, $siteIds[0], $periods, $timezone, $report);
    }

    private function handleRealtime(ApiRequest $request): Response
    {
        $query = $request->botTrackingRealtime
            ?? throw new LogicException('The BotTracking real-time request was not parsed.');
        $siteIds = $this->authorizedSiteIds($request, $query->siteIds, $query->allSites);

        if ($siteIds instanceof Response) {
            return $siteIds;
        }

        if ($request->format === 'rss' && count($siteIds) !== 1) {
            return $this->responses->error(
                $request,
                "RSS feeds can be generated for one specific website &idSite=X.\n".
                    'Please specify only one idSite or consider using &format=XML instead.',
                200,
            );
        }

        $report = $this->realtimeReports->build(
            $request->method,
            $siteIds,
            $query->lastMinutes,
            $request->showMetadata,
        );

        if ($request->format !== 'rss') {
            return $this->responses->tableReport($request, $report);
        }

        return $this->realtimeRssResponse($request, $siteIds[0], $report);
    }

    private function realtimeRssResponse(
        ApiRequest $request,
        int $idSite,
        ApiTableReport $report,
    ): Response {
        $timezone = $this->sites->timezone($idSite) ?? 'UTC';
        $date = CarbonImmutable::now($timezone)->toDateString();
        $period = new ReportingPeriod('day', 1, $date, $date, $date);
        $details = $this->sites->details($idSite);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';

        try {
            $content = $this->rss->table(
                new ApiTableReport([$date => $report->data], ['date']),
                [$period],
                $idSite,
                'day',
                $siteName,
                $timezone,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }

        return $this->responses->rss($content);
    }

    /**
     * @param  list<int>  $requestedSiteIds
     * @return list<int>|Response
     */
    private function authorizedSiteIds(
        ApiRequest $request,
        array $requestedSiteIds,
        bool $allSites,
    ): array|Response {
        $siteIds = $allSites
            ? $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $request->restrictSitesToLogin,
            )
            : $requestedSiteIds;

        if ($siteIds === []) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access.",
                401,
            );
        }

        if ($allSites) {
            return $siteIds;
        }

        foreach ($siteIds as $idSite) {
            if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                    401,
                );
            }
        }

        return $siteIds;
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     */
    private function rssResponse(
        ApiRequest $request,
        VisitsSummaryRequest $query,
        int $idSite,
        array $periods,
        string $timezone,
        ApiReport|ApiTableReport $report,
    ): Response {
        $details = $this->sites->details($idSite);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';

        try {
            $content = $report instanceof ApiReport
                ? $this->rss->report($report, $periods, $idSite, $query->period, $siteName, $timezone)
                : $this->rss->table($report, $periods, $idSite, $query->period, $siteName, $timezone);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }

        return $this->responses->rss($content);
    }
}
