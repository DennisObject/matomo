<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class SitesManagerTrackingCodeRequest
{
    /**
     * @param  list<array{0: string, 1: string}>  $visitorCustomVariables
     * @param  list<array{0: string, 1: string}>  $pageCustomVariables
     * @param  list<string>  $excludedQueryParameters
     * @param  list<string>  $excludedReferrers
     */
    public function __construct(
        public int $siteId,
        public string $matomoUrl,
        public bool $mergeSubdomains,
        public bool $groupPageTitlesByDomain,
        public bool $mergeAliasUrls,
        public array $visitorCustomVariables,
        public array $pageCustomVariables,
        public string $campaignNameParameter,
        public string $campaignKeywordParameter,
        public bool $doNotTrack,
        public bool $disableCookies,
        public bool $trackNoScript,
        public bool $crossDomain,
        public bool $forceMatomoEndpoint,
        public array $excludedQueryParameters,
        public array $excludedReferrers,
        public bool $disableCampaignParameters,
        public ?string $actionName,
        public int|false $goalId,
        public float|false $revenue,
    ) {}
}
