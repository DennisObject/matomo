<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isTransitionsTranslationsRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The Transitions API handler does not support this request.');
        }

        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $translations = [];

        foreach (self::TRANSLATIONS as $name => $key) {
            $translations[$name] = $this->translator->translate($key, $language);
        }

        return $this->responses->row($request, $translations);
    }
}
