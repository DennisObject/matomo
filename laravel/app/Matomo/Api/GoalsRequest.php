<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class GoalsRequest
{
    /**
     * @param  list<int>  $siteIds
     */
    public function __construct(
        public array $siteIds,
        public bool $allSites,
        public ?int $idGoal,
        public bool $orderByName,
        public ?GoalDefinition $definition,
    ) {}
}
