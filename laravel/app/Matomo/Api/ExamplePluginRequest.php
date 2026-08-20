<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ExamplePluginRequest
{
    /** @param list<int> $siteIds */
    public function __construct(
        public bool $truth,
        public ?string $segment,
        public array $siteIds,
        public bool $allSites,
    ) {}
}
