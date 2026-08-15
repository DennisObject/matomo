<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class InsightsRequest
{
    public function __construct(
        public string $period,
        public string $date,
        public ?int $siteId = null,
        public ?string $reportUniqueId = null,
        public ?string $segment = null,
        public int $comparedToXPeriods = 1,
        public int $limitIncreaser = 5,
        public int $limitDecreaser = 5,
        public string $filterBy = '',
        public int $minImpactPercent = 2,
        public int $minGrowthPercent = 20,
        public string $orderBy = 'absolute',
    ) {}
}
