<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Reporting\ReportingPeriodFactory;
use App\Matomo\Reporting\ReportingSettings;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Transitions\TransitionsPeriodPolicy;
use App\Matomo\Transitions\TransitionsReportBuilderFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class TransitionsApiMethodHandler implements ApiMethodHandler
{
    private const array TRANSLATIONS = [
        'pageviewsInline' => 'Transitions_NumPageviews',
        'loopsInline' => 'Transitions_LoopsInline',
        'fromPreviousPages' => 'Transitions_FromPreviousPages',
        'fromPreviousPagesInline' => 'Transitions_FromPreviousPagesInline',
        'fromPreviousSiteSearches' => 'Transitions_FromPreviousSiteSearches',
        'fromPreviousSiteSearchesInline' => 'Transitions_FromPreviousSiteSearchesInline',
        'fromSearchEngines' => 'Transitions_FromSearchEngines',
        'fromSearchEnginesInline' => 'Referrers_TypeSearchEngines',
        'fromSocialNetworks' => 'Transitions_FromSocialNetworks',
        'fromSocialNetworksInline' => 'Referrers_TypeSocialNetworks',
        'fromAIAssistants' => 'Transitions_FromAIAssistants',
        'fromAIAssistantsInline' => 'Referrers_TypeAIAssistants',
        'fromWebsites' => 'Transitions_FromWebsites',
        'fromWebsitesInline' => 'Referrers_TypeWebsites',
        'fromCampaigns' => 'Transitions_FromCampaigns',
        'fromCampaignsInline' => 'Referrers_TypeCampaigns',
        'directEntries' => 'Transitions_DirectEntries',
        'directEntriesInline' => 'Referrers_TypeDirectEntries',
        'toFollowingPages' => 'Transitions_ToFollowingPages',
        'toFollowingPagesInline' => 'Transitions_ToFollowingPagesInline',
        'toFollowingSiteSearches' => 'Transitions_ToFollowingSiteSearches',
        'toFollowingSiteSearchesInline' => 'Transitions_ToFollowingSiteSearchesInline',
        'downloads' => 'General_Downloads',
        'downloadsInline' => 'Transitions_NumDownloads',
        'outlinks' => 'General_Outlinks',
        'outlinksInline' => 'Transitions_NumOutlinks',
        'exits' => 'General_ColumnExits',
        'exitsInline' => 'Transitions_ExitsInline',
        'bouncesInline' => 'Transitions_BouncesInline',
        'XOfY' => 'Transitions_XOutOfYVisits',
        'XOfAllPageviews' => 'Transitions_XOfAllPageviews',
        'NoDataForAction' => 'Transitions_NoDataForAction',
        'NoDataForActionDetails' => 'Transitions_NoDataForActionDetails',
        'NoDataForActionBack' => 'Transitions_ErrorBack',
        'PeriodNotAllowed' => 'Transitions_PeriodNotAllowed',
        'PeriodNotAllowedDetails' => 'Transitions_PeriodNotAllowedDetails',
        'PeriodNotAllowedBack' => 'Transitions_ErrorBack',
        'ShareOfAllPageviews' => 'Transitions_ShareOfAllPageviews',
        'DateRange' => 'General_DateRange',
    ];

    public function __construct(
        private ApiResponseFactory $responses,
        private ApiAccessAuthorizer $authorizer,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
        private TransitionsPeriodPolicy $periods,
        private ReportingPeriodFactory $reportingPeriods,
        private ReportingSettings $reportingSettings,
        private SiteRepository $sites,
        private TransitionsReportBuilderFactory $reportBuilders,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isTransitionsRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Transitions API handler does not support this request.');
        }

        if ($request->method === 'Transitions.getTranslations') {
            $language = $this->languages->resolve($httpRequest, $request->authentication);
            $translations = [];

            foreach (self::TRANSLATIONS as $name => $key) {
                $translations[$name] = $this->translator->translate($key, $language);
            }

            return $this->responses->row($request, $translations);
        }

        if ($request->method === 'Transitions.isPeriodAllowed') {
            $parameters = $request->transitions
                ?? throw new LogicException('The Transitions period parameters were not parsed.');

            try {
                $allowed = $this->periods->isAllowed(
                    $parameters->siteId,
                    $parameters->period,
                    $parameters->date,
                );
            } catch (InvalidArgumentException $invalidArgumentException) {
                return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
            }

            return $this->responses->scalar($request, $allowed);
        }

        $parameters = $request->transitions
            ?? throw new LogicException('The Transitions report parameters were not parsed.');

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

        if (! $this->periods->isAllowed(
            $parameters->siteId,
            $parameters->period,
            $parameters->date,
        )) {
            return $this->responses->error($request, 'PeriodNotAllowed', 400);
        }

        if (! $this->reportingSettings->periodEnabled($parameters->period)) {
            return $this->responses->error(
                $request,
                "The period '{$parameters->period}' is not enabled.",
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
            [$periods] = $this->reportingPeriods->make(
                $parameters->period,
                $parameters->date,
                $timezone,
            );

            if ($periods === []) {
                throw new InvalidArgumentException('The requested period did not produce any dates.');
            }

            $report = $this->reportBuilders->make()->build(
                $parameters,
                $periods,
                $timezone,
                $this->languages->resolve($httpRequest, $request->authentication),
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->structured($request, $report);
    }
}
