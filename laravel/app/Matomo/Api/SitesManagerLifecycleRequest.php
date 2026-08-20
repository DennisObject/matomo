<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class SitesManagerLifecycleRequest
{
    /**
     * @param  list<string>|null  $urls
     * @param  array<string, list<array{name: string, value: mixed}>>  $settingValues
     */
    public function __construct(
        public ?int $siteId,
        public ?string $siteName,
        public ?array $urls,
        public ?int $ecommerce,
        public ?int $siteSearch,
        public ?string $searchKeywordParameters,
        public ?string $searchCategoryParameters,
        public ?string $excludedIps,
        public ?string $excludedQueryParameters,
        public ?string $timezone,
        public ?string $currency,
        public ?string $group,
        public ?string $startDate,
        public ?string $excludedUserAgents,
        public ?int $keepUrlFragments,
        public ?string $type,
        public array $settingValues,
        public ?bool $excludeUnknownUrls,
        public ?string $excludedReferrers,
        public ?string $description,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
