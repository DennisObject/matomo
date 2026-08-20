<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\ExamplePluginReportBuilder;
use App\Matomo\Reporting\ReportingPeriod;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\RssReportRenderer;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class ExamplePluginApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private ReportingPeriodFactory $periods,
        private ReportingSettings $settings,
        private SegmentHashResolver $segments,
        private ExamplePluginReportBuilder $reports,
        private RssReportRenderer $rss,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isExamplePluginRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The ExamplePlugin API handler does not support this request.');
        }

        $parameters = $request->examplePlugin
            ?? throw new LogicException('The ExamplePlugin request was not parsed.');

        if ($request->method === 'ExamplePlugin.getAnswerToLife') {
            return $this->responses->scalar($request, $parameters->truth ? 42 : 24);
        }

        if ($request->method === 'ExamplePlugin.getSegmentHash') {
            $siteIds = $parameters->allSites
                ? $this->authorizer->siteIdsWithAtLeastViewAccess(
                    $request->authentication,
                    $request->restrictSitesToLogin,
                )
                : $parameters->siteIds;
            $accessError = $this->viewAccessError($request, $siteIds, $parameters->allSites);

            if ($accessError !== null) {
                return $accessError;
            }

            return $this->responses->scalar(
                $request,
                $this->segments->resolve($parameters->segment),
            );
        }

        $query = $request->visitsSummary
            ?? throw new LogicException('The ExamplePlugin report request was not parsed.');
        $siteIds = $query->allSites
            ? $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $request->restrictSitesToLogin,
            )
            : $query->siteIds;
        $accessError = $this->viewAccessError($request, $siteIds, $query->allSites);

        if ($accessError !== null) {
            return $accessError;
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

        if ($request->method === 'ExamplePlugin.getExampleReport') {
            $report = new ApiTableReport([
                ['label' => 'My Label 1', 'nb_visits' => '1'],
                ['label' => 'My Label 2', 'nb_visits' => '5'],
            ], []);

            if ($request->format !== 'rss') {
                return $this->responses->tableReport($request, $report);
            }

            return $this->rssTable($request, $report, $periods, $siteIds[0], $query->period, $timezone);
        }

        $report = $this->reports->build(
            siteIds: $siteIds,
            periods: $periods,
            segmentHash: $this->segments->resolve($query->segment),
            forceSiteIndex: $query->allSites || count($siteIds) > 1,
            forceDateIndex: $forceDateIndex,
        );

        if ($request->format !== 'rss') {
            return $this->responses->report($request, $report);
        }

        $details = $this->sites->details($siteIds[0]);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';

        try {
            $content = $this->rss->report(
                $report,
                $periods,
                $siteIds[0],
                $query->period,
                $siteName,
                $timezone,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }

        return $this->responses->rss($content);
    }

    /** @param list<int> $siteIds */
    private function viewAccessError(ApiRequest $request, array $siteIds, bool $allSites): ?Response
    {
        if ($siteIds === []) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access.",
                401,
            );
        }

        if ($allSites) {
            return null;
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

        return null;
    }

    /** @param list<ReportingPeriod> $periods */
    private function rssTable(
        ApiRequest $request,
        ApiTableReport $report,
        array $periods,
        int $idSite,
        string $period,
        string $timezone,
    ): Response {
        $details = $this->sites->details($idSite);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';

        try {
            $content = $this->rss->table($report, $periods, $idSite, $period, $siteName, $timezone);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }

        return $this->responses->rss($content);
    }
}
