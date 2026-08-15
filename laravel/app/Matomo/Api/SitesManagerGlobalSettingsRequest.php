<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class SitesManagerGlobalSettingsRequest
{
    public function __construct(
        public ?string $excludedIps,
        public ?string $searchKeywordParameters,
        public ?string $searchCategoryParameters,
        public ?string $excludedUserAgents,
        public ?string $excludedReferrers,
        public ?bool $keepUrlFragments,
        public ?string $queryParameterExclusionType,
        public ?string $queryParametersToExclude,
    ) {}
}
