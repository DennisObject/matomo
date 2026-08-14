<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiMetricReport;
use App\Matomo\Api\ApiReport;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\VisitsSummaryRequest;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\DurationFormatter;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\RssReportRenderer;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Reporting\VisitsSummaryReportBuilder;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class VisitsSummaryApiMethodHandler implements ApiMethodHandler
{
    /** @var array<string, string> */
    private const array METRICS = [
        'VisitsSummary.getVisits' => 'nb_visits',
        'VisitsSummary.getUniqueVisitors' => 'nb_uniq_visitors',
        'VisitsSummary.getUsers' => 'nb_users',
        'VisitsSummary.getActions' => 'nb_actions',
        'VisitsSummary.getMaxActions' => 'max_actions',
        'VisitsSummary.getBounceCount' => 'bounce_count',
        'VisitsSummary.getVisitsConverted' => 'nb_visits_converted',
        'VisitsSummary.getSumVisitsLength' => 'sum_visit_length',
        'VisitsSummary.getSumVisitsLengthPretty' => 'sum_visit_length',
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private ReportingPeriodFactory $periods,
        private ReportingSettings $settings,
        private SegmentHashResolver $segments,
        private VisitsSummaryReportBuilder $reports,
        private DurationFormatter $durations,
        private RssReportRenderer $rss,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isVisitsSummaryRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The VisitsSummary API handler does not support this request.');
        }

        $query = $request->visitsSummary
            ?? throw new LogicException('The VisitsSummary request was not parsed.');
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
            return $this->responses->error(
                $request,
                "The period '{$query->period}' is not enabled.",
                400,
            );
        }

        $metric = self::METRICS[$request->method] ?? null;

        if (in_array($metric, ['nb_uniq_visitors', 'nb_users'], true)
            && ! $this->settings->uniqueVisitorsEnabled($query->period)) {
            return $this->responses->error(
                $request,
                "The metric {$metric} is not enabled for the requested period.",
                400,
            );
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

        $report = $this->reports->build(
            siteIds: $siteIds,
            periods: $periods,
            requestedPeriod: $query->period,
            segmentHash: $this->segments->resolve($query->segment),
            requestedColumns: $metric === null ? $query->columns : [$metric],
            showColumns: $metric === null ? $query->showColumns : [],
            hideColumns: $metric === null ? $query->hideColumns : [],
            forceSiteIndex: $query->allSites || count($siteIds) > 1,
            forceDateIndex: $forceDateIndex,
        );

        if ($metric === null) {
            return $request->format === 'rss'
                ? $this->rssResponse($request, $query, $siteIds, $periods, $timezone, $report)
                : $this->responses->report($request, $report);
        }

        $metricReport = ApiMetricReport::fromReport($report, $metric);

        if ($request->method === 'VisitsSummary.getSumVisitsLengthPretty') {
            $metricReport = $metricReport->map($this->durations->sentence(...));
        }

        return $request->format === 'rss'
            ? $this->rssResponse($request, $query, $siteIds, $periods, $timezone, $report, $metricReport)
            : $this->responses->metricReport($request, $metricReport);
    }

    /**
     * @param  list<int>  $siteIds
     * @param  list<ReportingPeriod>  $periods
     */
    private function rssResponse(
        ApiRequest $request,
        VisitsSummaryRequest $query,
        array $siteIds,
        array $periods,
        string $timezone,
        ApiReport $report,
        ?ApiMetricReport $metricReport = null,
    ): Response {
        $details = $this->sites->details($siteIds[0]);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';

        try {
            $content = $metricReport === null
                ? $this->rss->report($report, $periods, $siteIds[0], $query->period, $siteName, $timezone)
                : $this->rss->metric($metricReport, $periods, $siteIds[0], $query->period, $siteName, $timezone);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }

        return $this->responses->rss($content);
    }
}
