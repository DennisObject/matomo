<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Reporting\ReferrersTypeReportBuilder;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class ReferrersTypeApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private SiteRepository $sites,
        private ReportingPeriodFactory $periods,
        private ReportingSettings $settings,
        private SegmentHashResolver $segments,
        private LanguageResolver $languages,
        private ReferrersTypeReportBuilder $reports,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isReferrersTypeRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Referrers type API handler does not support this request.');
        }

        $query = $request->visitsSummary
            ?? throw new LogicException('The Referrers type request was not parsed.');

        if ($query->allSites || count($query->siteIds) !== 1) {
            return $this->responses->error(
                $request,
                "{$request->method} with multiple sites is not supported (yet).",
                400,
            );
        }

        $idSite = $query->siteIds[0];

        if (! $this->authorizer->hasViewAccessToSite($request->authentication, $idSite)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$idSite}.",
                401,
            );
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

        $timezone = $this->sites->timezone($idSite) ?? 'UTC';

        try {
            [$periods, $forceDateIndex] = $this->periods->make($query->period, $query->date, $timezone);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        if ($request->method === 'Referrers.getAll' && $forceDateIndex) {
            return $this->responses->error(
                $request,
                'Referrers.getAll with multiple sites or dates is not supported (yet).',
                400,
            );
        }

        $report = $this->reports->build(
            method: $request->method,
            typeReferrer: $query->typeReferrer,
            idSubtable: $query->idSubtable,
            expanded: $query->expanded,
            setReferrerTypeLabel: $query->setReferrerTypeLabel,
            siteIds: [$idSite],
            periods: $periods,
            segmentHash: $this->segments->resolve($query->segment),
            language: $this->languages->resolve($httpRequest, $request->authentication),
            showMetadata: $request->showMetadata,
            formatMetrics: $query->formatMetrics,
            forceDateIndex: $forceDateIndex,
        );

        return $this->responses->tableReport($request, $report);
    }
}
