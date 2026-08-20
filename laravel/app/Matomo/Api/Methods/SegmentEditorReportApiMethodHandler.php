<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Segments\SegmentDataReportBuilder;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class SegmentEditorReportApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private ReportingSettings $settings,
        private SiteRepository $sites,
        private SegmentDataReportBuilder $reports,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->module === 'API' && $request->method === 'SegmentEditor.getSegmentData';
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The SegmentEditor report API handler does not support this request.');
        }

        $parameters = $request->segmentEditor
            ?? throw new LogicException('The SegmentEditor API parameters were not parsed.');
        $siteId = $parameters->siteId
            ?? throw new LogicException('The site ID was not parsed.');

        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        $period = $parameters->period ?? '';

        if (! $this->settings->periodEnabled($period)) {
            return $this->responses->error($request, "The period '{$period}' is not enabled.", 400);
        }

        try {
            $report = $this->reports->build(
                $siteId,
                $period,
                $parameters->date ?? '',
                $parameters->segment ?? '',
                $this->sites->timezone($siteId) ?? 'UTC',
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->row($request, $report);
    }
}
