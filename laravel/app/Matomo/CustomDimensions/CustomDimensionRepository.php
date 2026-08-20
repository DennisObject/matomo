<?php

declare(strict_types=1);

namespace App\Matomo\CustomDimensions;

interface CustomDimensionRepository
{
    /** @return list<array<string, bool|int|string|list<array<string, mixed>>>> */
    public function configuredForSite(int $siteId): array;

    /** @return array<string, bool|int|string|list<array<string, mixed>>>|null */
    public function find(int $siteId, int $dimensionId): ?array;

    /** @return list<int> */
    public function installedIndexes(string $scope): array;

    /** @param list<array{dimension: string, pattern: string}> $extractions */
    public function create(
        int $siteId,
        string $name,
        string $scope,
        bool $active,
        array $extractions,
        bool $caseSensitive,
        string $description,
    ): int;

    /** @param list<array{dimension: string, pattern: string}> $extractions */
    public function update(
        int $siteId,
        int $dimensionId,
        string $name,
        bool $active,
        array $extractions,
        bool $caseSensitive,
        string $description,
    ): void;
}
