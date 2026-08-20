<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type Columns array<string, ArchiveValue>
 * @phpstan-type Metadata array<string, ArchiveValue>
 */
final class RecursiveArchiveNode
{
    /** @var array<string, self> */
    public array $children = [];

    /**
     * @param  Columns  $columns
     * @param  Metadata  $metadata
     */
    public function __construct(
        public array $columns,
        public array $metadata = [],
    ) {}
}
