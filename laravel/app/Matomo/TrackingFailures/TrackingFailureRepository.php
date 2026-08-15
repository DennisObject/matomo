<?php

declare(strict_types=1);

namespace App\Matomo\TrackingFailures;

interface TrackingFailureRepository
{
    /** @return list<array<string, int|string>> */
    public function all(): array;

    /**
     * @param  list<int>  $siteIds
     * @return list<array<string, int|string>>
     */
    public function forSites(array $siteIds): array;

    public function deleteAll(): void;

    /** @param list<int> $siteIds */
    public function deleteForSites(array $siteIds): void;

    public function delete(int $siteId, int|string $failureId): void;
}
