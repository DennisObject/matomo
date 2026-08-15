<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

final readonly class DataSubjectLogTable
{
    public function __construct(
        public string $name,
        public string $visitColumn,
        public string $siteColumn,
        public string $orderColumn,
        public int $deletionPriority,
    ) {}
}
