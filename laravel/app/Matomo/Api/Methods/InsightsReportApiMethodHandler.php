<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\ApiTableReport;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Insights\InsightReportBuilder;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class InsightsReportApiMethodHandler implements ApiMethodHandler
{
    private const array METHODS = [
        'Insights.getMoversAndShakers',
        'Insights.getInsights',
    ];

    public function __construct(
        private ApiResponseFactory $responses,
        private ApiAccessAuthorizer $authorizer,
        private SiteRepository $sites,
        private ReportingSettings $settings,
        private LanguageResolver $languages,
        private InsightReportBuilder $reports,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return in_array($request->method, self::METHODS, true);
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Insights report API handler does not support this request.');
        }

        $parameters = $request->insights
            ?? throw new LogicException('The Insights report parameters were not parsed.');
        $siteId = $parameters->siteId
            ?? throw new LogicException('The Insights website ID was not parsed.');

        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                401,
            );
        }

        if (! $this->settings->periodEnabled($parameters->period)) {
            return $this->responses->error(
                $request,
                "The period '{$parameters->period}' is not enabled.",
                400,
            );
        }

        if ($parameters->segment !== null
            && ! $this->settings->anonymousSegmentsEnabled()
            && $this->authorizer->authenticatedLogin($request->authentication) === 'anonymous') {
            return $this->responses->error(
                $request,
                'The Super User has disabled the Segmentation feature.',
                401,
            );
        }

        $timezone = $this->sites->timezone($siteId);

        if ($timezone === null) {
            return $this->responses->error(
                $request,
                "The website id = {$siteId} does not exist.",
                400,
            );
        }

        try {
            $report = $this->reports->build(
                $parameters,
                $timezone,
                $this->languages->resolve($httpRequest, $request->authentication),
                $request->method === 'Insights.getMoversAndShakers',
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->tableReport($request, new ApiTableReport($report->rows, []));
    }
}
