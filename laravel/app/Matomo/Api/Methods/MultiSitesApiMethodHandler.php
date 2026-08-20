<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\MultiSites\Events\MultiSitesFiltering;
use App\Matomo\MultiSites\MultiSitesReportBuilder;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\RssReportRenderer;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteRuntimeSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class MultiSitesApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private SiteRuntimeSettings $siteSettings,
        private ReportingPeriodFactory $periods,
        private ReportingSettings $reportingSettings,
        private SegmentHashResolver $segments,
        private MultiSitesReportBuilder $reports,
        private RssReportRenderer $rss,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isMultiSitesRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The MultiSites API handler does not support this request.');
        }

        $query = $request->multiSites
            ?? throw new LogicException('The MultiSites request was not parsed.');

        if ($request->method === 'MultiSites.getOne'
            && ! $this->authorizer->hasViewAccessToSite(
                $request->authentication,
                $query->siteId ?? 0,
            )) {
            $siteId = $query->siteId ?? 0;

            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        if ($request->method !== 'MultiSites.getOne'
            && ! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access.",
                401,
            );
        }

        if (! $this->reportingSettings->periodEnabled($query->period)) {
            return $this->responses->error(
                $request,
                "The period '{$query->period}' is not enabled.",
                400,
            );
        }

        if ($query->segment !== null
            && ! $this->reportingSettings->anonymousSegmentsEnabled()
            && $this->authorizer->authenticatedLogin($request->authentication) === 'anonymous') {
            return $this->responses->error(
                $request,
                'The Super User has disabled the Segmentation feature.',
                401,
            );
        }

        $siteIds = $this->siteIds($request);

        if ($request->method !== 'MultiSites.getOne') {
            $siteIds = $this->filterSiteIds($siteIds);
        }

        $timezone = count($siteIds) === 1
            ? ($this->sites->timezone($siteIds[0]) ?? 'UTC')
            : 'UTC';

        try {
            [$periods, $forceDateIndex] = $this->periods->make(
                $query->period,
                $query->date,
                $timezone,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        if ($request->method === 'MultiSites.getAllWithGroups' && $forceDateIndex) {
            return $this->responses->error($request, 'Multiple periods are not supported', 400);
        }

        if ($siteIds === []) {
            return $request->method === 'MultiSites.getAllWithGroups'
                ? $this->responses->structured($request, [
                    'numSites' => 0,
                    'totals' => [],
                    'lastDate' => '',
                    'sites' => [],
                ])
                : $this->responses->tableReport($request, new ApiTableReport([], []));
        }

        $showColumns = $request->method === 'MultiSites.getAll' ? $query->showColumns : [];
        $report = $this->reports->build(
            siteIds: $siteIds,
            periods: $periods,
            requestedPeriod: $query->period,
            requestedDate: $query->date,
            timezone: $timezone,
            segmentHash: $this->segments->resolve($query->segment),
            enhanced: $request->method === 'MultiSites.getAllWithGroups' || $query->enhanced,
            showColumns: $showColumns,
            includeZeroVisitRows: $request->method !== 'MultiSites.getAll'
                || $query->enhanced
                || ($showColumns !== [] && ! in_array('nb_visits', $showColumns, true)),
            includeSiteLabel: $request->method !== 'MultiSites.getOne',
        );

        if ($request->method === 'MultiSites.getAllWithGroups') {
            $period = $periods[0];

            return $this->responses->structured(
                $request,
                $this->reports->grouped(
                    rows: $report->rowsByPeriod[$period->resultKey] ?? [],
                    totals: $report->totalsByPeriod[$period->resultKey] ?? [],
                    lastDate: $report->lastDate,
                    pattern: $query->pattern,
                    offset: $query->filterOffset,
                    limit: $query->filterLimit,
                    sortColumn: $query->filterSortColumn,
                    sortOrder: $query->filterSortOrder,
                    formatMetrics: $query->formatMetrics,
                ),
            );
        }

        $data = $forceDateIndex
            ? $report->rowsByPeriod
            : ($report->rowsByPeriod[$periods[0]->resultKey] ?? []);
        $tableReport = new ApiTableReport($data, $forceDateIndex ? ['date'] : []);

        if ($request->format !== 'rss') {
            return $this->responses->tableReport($request, $tableReport);
        }

        if ($request->method !== 'MultiSites.getOne') {
            return $this->responses->error(
                $request,
                "RSS feeds can be generated for one specific website &idSite=X.\n".
                    'Please specify only one idSite or consider using &format=XML instead.',
                200,
            );
        }

        return $this->rssResponse($request, $siteIds[0], $query->period, $periods, $timezone, $report->rowsByPeriod);
    }

    /** @return list<int> */
    private function siteIds(ApiRequest $request): array
    {
        $query = $request->multiSites
            ?? throw new LogicException('The MultiSites request was not parsed.');

        if ($request->method === 'MultiSites.getOne') {
            $siteId = $query->siteId ?? 0;

            return $siteId > 0 && $this->sites->details($siteId) !== []
                    ? [$siteId]
                    : [];
        }

        $siteIds = $this->authorizer->siteIdsWithAtLeastViewAccess(
            $request->authentication,
            $request->restrictSitesToLogin,
        );

        if ($request->method !== 'MultiSites.getAll' || $query->pattern === null || $query->pattern === '') {
            return $siteIds;
        }

        $details = $this->sites->detailsForIds(
            $siteIds,
            $query->pattern,
            $this->siteSettings->websitesCountToDisplay(),
        );

        return array_values(array_filter(array_map(
            static fn (array $site): ?int => is_int($site['idsite'] ?? null) ? $site['idsite'] : null,
            $details,
        ), is_int(...)));
    }

    /**
     * An extension may remove or reorder sites, but it cannot grant access to another site.
     *
     * @param  list<int>  $authorizedSiteIds
     * @return list<int>
     */
    private function filterSiteIds(array $authorizedSiteIds): array
    {
        $event = new MultiSitesFiltering($authorizedSiteIds);
        $this->events->dispatch($event);
        $allowed = array_flip($authorizedSiteIds);
        $siteIds = [];

        foreach ($event->siteIds as $siteId) {
            if (isset($allowed[$siteId])) {
                $siteIds[] = $siteId;
            }
        }

        return array_values(array_unique($siteIds));
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     * @param  array<string, list<array<string, float|int|string|null>>>  $rowsByPeriod
     */
    private function rssResponse(
        ApiRequest $request,
        int $siteId,
        string $period,
        array $periods,
        string $timezone,
        array $rowsByPeriod,
    ): Response {
        $details = $this->sites->details($siteId);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';
        try {
            $content = $this->rss->table(
                new ApiTableReport($rowsByPeriod, ['date']),
                $periods,
                $siteId,
                $period,
                $siteName,
                $timezone,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }

        return $this->responses->rss($content);
    }
}
