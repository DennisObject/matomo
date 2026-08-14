<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiMetricReport;
use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\DurationFormatter;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
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
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isVisitsSummaryRequest() && $request->format !== 'rss';
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
            return $this->responses->report($request, $report);
        }

        $metricReport = ApiMetricReport::fromReport($report, $metric);

        if ($request->method === 'VisitsSummary.getSumVisitsLengthPretty') {
            $metricReport = $metricReport->map($this->durations->sentence(...));
        }

        return $this->responses->metricReport($request, $metricReport);
    }
}
