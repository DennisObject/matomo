<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Reporting\CustomDimensionsReportBuilder;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Reporting\RssReportRenderer;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class CustomDimensionsReportApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private CustomDimensionRepository $dimensions,
        private SiteRepository $sites,
        private ReportingPeriodFactory $periods,
        private ReportingSettings $settings,
        private SegmentHashResolver $segments,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
        private CustomDimensionsReportBuilder $reports,
        private RssReportRenderer $rss,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->module === 'API' && $request->method === 'CustomDimensions.getCustomDimension';
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The CustomDimensions report handler does not support this request.');
        }

        $customDimension = $request->customDimensions
            ?? throw new LogicException('The CustomDimensions request was not parsed.');
        $query = $request->visitsSummary
            ?? throw new LogicException('The CustomDimensions report request was not parsed.');
        $dimensionId = $customDimension->dimensionId
            ?? throw new LogicException('The dimension ID was not parsed.');
        $siteIds = $query->allSites
            ? $this->authorizer->siteIdsWithAtLeastViewAccess(
                $request->authentication,
                $request->restrictSitesToLogin,
            )
            : $query->siteIds;

        if ($siteIds === []) {
            return $this->responses->error($request, "You can't access this resource as it requires 'view' access.", 401);
        }

        foreach ($siteIds as $siteId) {
            if (! $query->allSites && ! $this->authorizer->hasViewAccessToSite($request->authentication, $siteId)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'view' access for the website id = {$siteId}.",
                    401,
                );
            }

            $dimension = $this->dimensions->find($siteId, $dimensionId);

            if ($dimension === null) {
                return $this->responses->error($request, $this->translator->translate(
                    'CustomDimensions_ExceptionDimensionDoesNotExist',
                    $this->languages->resolve($httpRequest, $request->authentication),
                    [$dimensionId, $siteId],
                ), 400);
            }

            if (($dimension['active'] ?? false) !== true) {
                return $this->responses->error($request, $this->translator->translate(
                    'CustomDimensions_ExceptionDimensionIsNotActive',
                    $this->languages->resolve($httpRequest, $request->authentication),
                    [$dimensionId, $siteId],
                ), 400);
            }
        }

        if (! $this->settings->periodEnabled($query->period)) {
            return $this->responses->error($request, "The period '{$query->period}' is not enabled.", 400);
        }

        if ($query->segment !== null
            && ! $this->settings->anonymousSegmentsEnabled()
            && $this->authorizer->authenticatedLogin($request->authentication) === 'anonymous') {
            return $this->responses->error($request, 'The Super User has disabled the Segmentation feature.', 401);
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

        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $report = $this->reports->build(
            dimensionId: $dimensionId,
            idSubtable: $query->idSubtable,
            siteIds: $siteIds,
            periods: $periods,
            segmentHash: $this->segments->resolve($query->segment),
            expanded: $query->expanded,
            flat: $query->flat,
            language: $language,
            showMetadata: $request->showMetadata,
            forceSiteIndex: $query->allSites || count($siteIds) > 1,
            forceDateIndex: $forceDateIndex,
        );

        if ($request->format !== 'rss') {
            return $this->responses->tableReport($request, $report);
        }

        $details = $this->sites->details($siteIds[0]);
        $siteName = is_string($details['name'] ?? null) ? $details['name'] : '';

        try {
            return $this->responses->rss($this->rss->table(
                $report,
                $periods,
                $siteIds[0],
                $query->period,
                $siteName,
                $timezone,
            ));
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 200);
        }
    }
}
