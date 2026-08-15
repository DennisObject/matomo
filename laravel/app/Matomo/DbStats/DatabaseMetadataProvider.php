<?php

declare(strict_types=1);

namespace App\Matomo\DbStats;

/**
 * @phpstan-type TableStatus array{name: string, dataLength: int, indexLength: int, rows: int}
 */
interface DatabaseMetadataProvider
{
    public function tablePrefix(): string;

    /** @return list<TableStatus> */
    public function tableStatuses(): array;

    /** @return array<string, int|string> */
    public function databaseStatus(): array;
}
