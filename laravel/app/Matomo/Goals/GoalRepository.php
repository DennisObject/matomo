<?php

declare(strict_types=1);

namespace App\Matomo\Goals;

use App\Matomo\Api\GoalDefinition;

interface GoalRepository
{
    /** @return array<string, float|int|string>|null */
    public function findActive(int $siteId, int $goalId): ?array;

    /**
     * @param  list<int>  $siteIds
     * @return list<array<string, float|int|string>>
     */
    public function activeForSites(array $siteIds): array;

    public function create(int $siteId, GoalDefinition $goal): int;

    public function update(int $siteId, int $goalId, GoalDefinition $goal): void;

    public function delete(int $siteId, int $goalId): void;
}
