<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class GoalsReportRequest
{
    public function __construct(
        public bool $abandonedCarts,
        public int|string|null $idGoal,
        public bool $showAllGoalSpecificMetrics,
        public bool $formatMetrics,
    ) {}
}
