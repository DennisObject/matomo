<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

/**
 * @phpstan-type StoredSegment array{
 *   idsegment: int,
 *   name: string,
 *   definition: string,
 *   hash: string,
 *   login: string,
 *   enable_all_users: int,
 *   enable_only_idsite: int|null,
 *   auto_archive: int,
 *   ts_created: string|null,
 *   ts_last_edit: string|null,
 *   deleted: int,
 *   starred: int,
 *   starred_by: string|null
 * }
 */
interface StoredSegmentRepository
{
    /** @return StoredSegment|null */
    public function find(int $segmentId): ?array;

    /** @return list<StoredSegment> */
    public function visible(string $login, bool $superUser, ?int $siteId): array;
}
