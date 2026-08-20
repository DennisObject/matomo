<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\DbStats\DbStatsReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class DbStatsApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private DbStatsReportBuilder $reports,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isDbStatsRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The DBStats API handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        return match ($request->method) {
            'DBStats.getGeneralInformation' => $this->responses->values(
                $request,
                $this->reports->generalInformation(),
            ),
            'DBStats.getDBStatus' => $this->responses->row(
                $request,
                $this->reports->databaseStatus(),
            ),
            'DBStats.getDatabaseUsageSummary' => $this->responses->tableReport(
                $request,
                $this->reports->databaseUsageSummary(),
            ),
            'DBStats.getTrackerDataSummary' => $this->responses->tableReport(
                $request,
                $this->reports->trackerDataSummary(),
            ),
            'DBStats.getMetricDataSummary' => $this->responses->tableReport(
                $request,
                $this->reports->metricDataSummary(),
            ),
            'DBStats.getMetricDataSummaryByYear' => $this->responses->tableReport(
                $request,
                $this->reports->metricDataSummary(true),
            ),
            'DBStats.getReportDataSummary' => $this->responses->tableReport(
                $request,
                $this->reports->reportDataSummary(),
            ),
            'DBStats.getReportDataSummaryByYear' => $this->responses->tableReport(
                $request,
                $this->reports->reportDataSummary(true),
            ),
            'DBStats.getAdminDataSummary' => $this->responses->tableReport(
                $request,
                $this->reports->adminDataSummary(),
            ),
            default => throw new LogicException('The DBStats API method is not implemented.'),
        };
    }
}
