<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Api\TransitionsRequest;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Overlay\OverlayReportContextFactory;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Transitions\TransitionsPeriodPolicy;
use App\Matomo\Transitions\TransitionsReportBuilderFactory;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class OverlayApiMethodHandler implements ApiMethodHandler
{
    private const array TRANSLATIONS = [
        'oneClick' => 'Overlay_OneClick',
        'clicks' => 'Overlay_Clicks',
        'clicksFromXLinks' => 'Overlay_ClicksFromXLinks',
        'link' => 'Overlay_Link',
    ];

    public function __construct(
        private ApiResponseFactory $responses,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
        private ApiAccessAuthorizer $authorizer,
        private OverlayReportContextFactory $contexts,
        private TransitionsPeriodPolicy $periods,
        private ReportingPeriodFactory $reportingPeriods,
        private ReportingSettings $reportingSettings,
        private SiteRepository $sites,
        private TransitionsReportBuilderFactory $reportBuilders,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isOverlayRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Overlay API handler does not support this request.');
        }

        if ($request->isOverlayTranslationsRequest()) {
            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $translations = [];

            foreach (self::TRANSLATIONS as $name => $key) {
                $translations[$name] = $this->translator->translate($key, $language);
            }

            return $this->responses->row($request, $translations);
        }

        $parameters = $request->overlay
            ?? throw new LogicException('The Overlay report parameters were not parsed.');

        if (! $this->authorizer->hasViewAccessToSite(
            $request->authentication,
            $parameters->siteId,
        )) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'view' access for the website id = {$parameters->siteId}.",
                401,
            );
        }

        $timezone = $this->sites->timezone($parameters->siteId);

        if ($timezone === null) {
            return $this->responses->error(
                $request,
                "The website id = {$parameters->siteId} does not exist.",
                400,
            );
        }

        if ($parameters->segment !== null
            && ! $this->reportingSettings->anonymousSegmentsEnabled()
            && $this->authorizer->authenticatedLogin($request->authentication) === 'anonymous') {
            return $this->responses->error(
                $request,
                'The Super User has disabled the Segmentation feature.',
                401,
            );
        }

        try {
            $context = $this->contexts->make();

            if (! $this->periods->isAllowed(
                $parameters->siteId,
                $parameters->period,
                $parameters->date,
            ) || ! $this->reportingSettings->periodEnabled($parameters->period)) {
                return $this->responses->rows($request, []);
            }

            $url = $context->pageUrls->filter($parameters->url, $parameters->siteId);

            if ($url === null) {
                return $this->responses->rows($request, []);
            }

            [$periods] = $this->reportingPeriods->make(
                $parameters->period,
                $parameters->date,
                $timezone,
            );

            if ($periods === []) {
                return $this->responses->rows($request, []);
            }

            $report = $this->reportBuilders->make()->build(
                new TransitionsRequest(
                    siteId: $parameters->siteId,
                    period: $parameters->period,
                    date: $parameters->date,
                    actionName: $url,
                    actionType: 'url',
                    segment: $parameters->segment,
                    limitBeforeGrouping: $context->followingPagesLimit,
                    parts: ['followingActions'],
                    allParts: false,
                ),
                $periods,
                $timezone,
                $this->languages->resolve($httpRequest, $request->authentication),
            );
        } catch (Exception) {
            return $this->responses->rows($request, []);
        }

        $rows = [];

        foreach (['followingPages', 'outlinks', 'downloads'] as $reportName) {
            $reportRows = $report[$reportName] ?? null;

            if (is_array($reportRows)) {
                array_push($rows, ...$reportRows);
            }
        }

        $rows = $parameters->filterLimit === -1
            ? array_slice($rows, $parameters->filterOffset)
            : array_slice($rows, $parameters->filterOffset, $parameters->filterLimit);

        return $this->responses->rows($request, $rows);
    }
}
