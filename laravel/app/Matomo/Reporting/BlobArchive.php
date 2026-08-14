<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

final readonly class BlobArchive
{
    /**
     * @param  list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>}>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $archivedAt,
    ) {}
}
