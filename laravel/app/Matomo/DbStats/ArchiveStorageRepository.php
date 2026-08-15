<?php

declare(strict_types=1);

namespace App\Matomo\DbStats;

interface ArchiveStorageRepository
{
    /**
     * @return list<array{label: string, row_count: int, blob_size: int, name_size: int}>
     */
    public function rowsByName(string $table, bool $includeBlobSizes): array;

    /** @return list<string> */
    public function columnTypes(string $table): array;
}
