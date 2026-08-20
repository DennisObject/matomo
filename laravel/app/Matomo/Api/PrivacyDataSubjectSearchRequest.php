<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyDataSubjectSearchRequest
{
    /** @param list<int> $siteIds */
    public function __construct(
        public array $siteIds,
        public bool $allSites,
        public string $segment,
    ) {}
}
