<?php

declare(strict_types=1);

namespace App\Matomo\DbStats;

use App\Matomo\Api\ApiTableReport;

/**
 * @phpstan-import-type TableStatus from DatabaseMetadataProvider
 */
final readonly class DbStatsReportBuilder
{
    public function __construct(
        private DatabaseMetadataProvider $metadata,
    ) {}

    /** @return list<int> */
    public function generalInformation(): array
    {
        $statuses = $this->metadata->tableStatuses();
        $byName = [];
        $total = 0;

        foreach ($statuses as $status) {
            $byName[$status['name']] = $status;
            $total += $status['dataLength'] + $status['indexLength'];
        }

        return [
            $byName[$this->prefix().'site']['rows'] ?? 0,
            $byName[$this->prefix().'user']['rows'] ?? 0,
            $total,
        ];
    }

    /** @return array<string, int|string> */
    public function databaseStatus(): array
    {
        return $this->metadata->databaseStatus();
    }

    public function databaseUsageSummary(): ApiTableReport
    {
        $rows = [
            'tracker_data' => $this->emptyRow(),
            'metric_data' => $this->emptyRow(),
            'report_data' => $this->emptyRow(),
            'other_data' => $this->emptyRow(),
        ];

        foreach ($this->metadata->tableStatuses() as $status) {
            $group = match (true) {
                $this->numericArchive($status['name']) => 'metric_data',
                $this->blobArchive($status['name']) => 'report_data',
                $this->tracker($status['name']) => 'tracker_data',
                default => 'other_data',
            };
            $this->addStatus($rows[$group], $status);
        }

        return $this->report($rows);
    }

    public function trackerDataSummary(): ApiTableReport
    {
        return $this->tableSummary(
            fn (string $name): bool => $this->tracker($name)
                && $name !== $this->prefix().'log_profiling',
        );
    }

    public function metricDataSummary(bool $byYear = false): ApiTableReport
    {
        return $this->tableSummary($this->numericArchive(...), $byYear);
    }

    public function reportDataSummary(bool $byYear = false): ApiTableReport
    {
        return $this->tableSummary($this->blobArchive(...), $byYear);
    }

    public function adminDataSummary(): ApiTableReport
    {
        return $this->tableSummary(
            fn (string $name): bool => str_starts_with($name, $this->prefix())
                && ! str_starts_with($name, $this->prefix().'archive_')
                && (! str_starts_with($name, $this->prefix().'log_')
                    || $name === $this->prefix().'log_profiling'),
        );
    }

    /** @param callable(string): bool $include */
    private function tableSummary(callable $include, bool $byYear = false): ApiTableReport
    {
        $rows = [];

        foreach ($this->metadata->tableStatuses() as $status) {
            if (! $include($status['name'])) {
                continue;
            }

            $label = $byYear ? $this->archiveYear($status['name']) : $status['name'];
            $rows[$label] ??= $this->emptyRow();
            $this->addStatus($rows[$label], $status);
        }

        return $this->report($rows);
    }

    /**
     * @param  array<string, array{data_size: int, index_size: int, row_count: int}>  $rows
     */
    private function report(array $rows): ApiTableReport
    {
        $data = [];

        foreach ($rows as $label => $metrics) {
            $data[] = ['label' => (string) $label, ...$metrics];
        }

        return new ApiTableReport($data, []);
    }

    /** @return array{data_size: int, index_size: int, row_count: int} */
    private function emptyRow(): array
    {
        return ['data_size' => 0, 'index_size' => 0, 'row_count' => 0];
    }

    /**
     * @param  array{data_size: int, index_size: int, row_count: int}  $row
     * @param  TableStatus  $status
     */
    private function addStatus(array &$row, array $status): void
    {
        $row['data_size'] += $status['dataLength'];
        $row['index_size'] += $status['indexLength'];
        $row['row_count'] += $status['rows'];
    }

    private function numericArchive(string $name): bool
    {
        return str_starts_with($name, $this->prefix().'archive_numeric_');
    }

    private function blobArchive(string $name): bool
    {
        return str_starts_with($name, $this->prefix().'archive_blob_');
    }

    private function tracker(string $name): bool
    {
        return str_starts_with($name, $this->prefix().'log_');
    }

    private function archiveYear(string $name): string
    {
        return preg_match('/archive_(?:numeric|blob)_([0-9]+)_/D', $name, $matches) === 1
            ? $matches[1]
            : '';
    }

    private function prefix(): string
    {
        return $this->metadata->tablePrefix();
    }
}
