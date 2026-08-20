<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

final readonly class ReportingPeriod
{
    public function __construct(
        public string $label,
        public int $id,
        public string $startDate,
        public string $endDate,
        public string $resultKey,
    ) {}

    public function rangeKey(): string
    {
        return $this->startDate.','.$this->endDate;
    }

    public function archiveTable(): string
    {
        return 'archive_numeric_'.str_replace('-', '_', substr($this->startDate, 0, 7));
    }
}
