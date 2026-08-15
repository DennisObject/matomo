<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

interface ScheduledReportRepository
{
    /** @param array<string, mixed> $report */
    public function create(array $report): int;

    /** @param array<string, mixed> $changes */
    public function update(int $idReport, array $changes): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function find(
        ?int $idSite,
        ?string $period,
        ?int $idReport,
        ?string $login,
        ?int $idSegment,
    ): array;
}
