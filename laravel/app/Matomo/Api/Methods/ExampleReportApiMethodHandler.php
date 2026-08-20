<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\RssReportRenderer;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class ExampleReportApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private ReportingPeriodFactory $periods,
        private RssReportRenderer $rss,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isExampleReportRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The ExampleReport API handler does not support this request.');
        }

        $query = $request->visitsSummary
            ?? throw new LogicException('The ExampleReport request was not parsed.');
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

        if ($request->format === 'rss' && count($siteIds) !== 1) {
            return $this->responses->error(
                $request,
                "RSS feeds can be generated for one specific website &idSite=X.\n".
                    'Please specify only one idSite or consider using &format=XML instead.',
                200,
            );
        }

        $rows = [['nb_visits' => 5]];
        $report = new ApiTableReport($rows, []);

        if ($request->format !== 'rss') {
            return $this->responses->tableReport($request, $report);
        }

        $timezone = $this->sites->timezone($siteIds[0]) ?? 'UTC';

        try {
            [$periods] = $this->periods->make($query->period, $query->date, $timezone);
            $dateRows = [];

            foreach ($periods as $period) {
                $dateRows[$period->resultKey] = $rows;
            }

            $details = $this->sites->details($siteIds[0]);
            $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';
            $content = $this->rss->table(
                new ApiTableReport($dateRows, ['date']),
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
}
