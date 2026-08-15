<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

interface MutableStoredSegmentRepository extends StoredSegmentRepository
{
    /** @param array<string, bool|int|string|null> $values */
    public function create(array $values): int;

    public function delete(int $segmentId, string $editedAt): void;

    /** @param array<string, bool|int|string|null> $values */
    public function update(int $segmentId, array $values): bool;
}
