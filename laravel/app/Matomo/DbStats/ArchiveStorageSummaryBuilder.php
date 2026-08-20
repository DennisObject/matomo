<?php

declare(strict_types=1);

namespace App\Matomo\DbStats;

use App\Matomo\Api\ApiTableReport;
use App\Matomo\Options\MutableOptionRepository;
use Closure;

/**
 * @phpstan-import-type TableStatus from DatabaseMetadataProvider
 *
 * @phpstan-type CachedRow array{label: string, row_count: int, blob_size?: int, name_size?: int}
 */
final readonly class ArchiveStorageSummaryBuilder
{
    public function __construct(
        private DatabaseMetadataProvider $metadata,
        /** @var Closure(): ArchiveStorageRepository */
        private Closure $storage,
        /** @var Closure(): MutableOptionRepository */
        private Closure $options,
    ) {}

    public function reports(bool $forceCache): ApiTableReport
    {
        return $this->summary('archive_blob_', true, $forceCache);
    }

    public function metrics(bool $forceCache): ApiTableReport
    {
        return $this->summary('archive_numeric_', false, $forceCache);
    }

    private function summary(string $archivePrefix, bool $includeBlobSizes, bool $forceCache): ApiTableReport
    {
        $rows = [];
        $physicalPrefix = $this->metadata->tablePrefix().$archivePrefix;
        $fixedSize = null;

        foreach ($this->metadata->tableStatuses() as $status) {
            if (! str_starts_with($status['name'], $physicalPrefix)) {
                continue;
            }

            foreach ($this->tableRows($status, $includeBlobSizes, $forceCache) as $row) {
                if ($includeBlobSizes && $fixedSize === null) {
                    $fixedSize = $this->fixedColumnSize($status['name']);
                }

                $label = $row['label'];
                $rows[$label] ??= [
                    'label' => $label,
                    'row_count' => 0,
                    'estimated_size' => 0.0,
                ];
                $rows[$label]['row_count'] += $row['row_count'];
                $rows[$label]['estimated_size'] += $includeBlobSizes
                    ? $this->estimatedBlobSize($row, $status, $fixedSize ?? 0)
                    : $this->estimatedMetricSize($row['row_count'], $status);
            }
        }

        return new ApiTableReport(array_values($rows), []);
    }

    /**
     * @param  TableStatus  $status
     * @return list<CachedRow>
     */
    private function tableRows(array $status, bool $includeBlobSizes, bool $forceCache): array
    {
        $optionName = 'dbstats_cached_'.$status['name'].'_byArchiveName';

        if (! $forceCache) {
            $cached = $this->decode($this->options()->value($optionName), $includeBlobSizes);

            if ($cached !== null) {
                return $cached;
            }
        }

        $grouped = [];

        foreach ($this->storage()->rowsByName($status['name'], $includeBlobSizes) as $row) {
            $label = $this->reduceArchiveRowName($row['label']);
            $grouped[$label] ??= [
                'label' => $label,
                'row_count' => 0,
                'blob_size' => 0,
                'name_size' => 0,
            ];
            $grouped[$label]['row_count'] += $row['row_count'];
            $grouped[$label]['blob_size'] += $row['blob_size'];
            $grouped[$label]['name_size'] += $row['name_size'];
        }

        $rows = array_values($grouped);
        $this->options()->set($optionName, $this->encode($rows, $includeBlobSizes));

        return $rows;
    }

    private function reduceArchiveRowName(string $name): string
    {
        if (str_starts_with($name, 'done')) {
            return 'done';
        }

        if (preg_match('/^Goal_(?:-?[0-9]+_)?(.*)/D', $name, $matches) === 1) {
            $name = 'Goal_*_'.$matches[1];
        }

        if (preg_match('/^(.*)_[0-9]+$/D', $name, $matches) === 1) {
            $name = $matches[1].'_*';
        }

        return $name;
    }

    /** @param TableStatus $status */
    private function estimatedMetricSize(int $rowCount, array $status): float
    {
        if ($status['rows'] === 0) {
            return 0.0;
        }

        return (($status['dataLength'] + $status['indexLength']) / $status['rows']) * $rowCount;
    }

    /**
     * @param  CachedRow  $row
     * @param  TableStatus  $status
     */
    private function estimatedBlobSize(array $row, array $status, int $fixedSize): float
    {
        $averageFixedSize = $status['rows'] === 0
            ? 0.0
            : ($status['indexLength'] / $status['rows']) + $fixedSize;

        return ($averageFixedSize * $row['row_count'])
            + ($row['blob_size'] ?? 0)
            + ($row['name_size'] ?? 0);
    }

    private function fixedColumnSize(string $table): int
    {
        $fixedSize = 0;

        foreach ($this->storage()->columnTypes($table) as $type) {
            $baseType = strstr($type, '(', true);
            $fixedSize += $this->databaseTypeSize(strtolower($baseType === false ? $type : $baseType));
        }

        return $fixedSize;
    }

    private function databaseTypeSize(string $type): int
    {
        return match ($type) {
            'tinyint', 'year' => 1,
            'smallint' => 2,
            'mediumint', 'date', 'time' => 3,
            'int', 'float', 'timestamp' => 4,
            'bigint', 'double', 'real', 'datetime' => 8,
            default => 0,
        };
    }

    /** @param list<CachedRow> $rows */
    private function encode(array $rows, bool $includeBlobSizes): string
    {
        $serializedRows = [];

        foreach ($rows as $row) {
            $columns = ['label' => $row['label'], 'row_count' => $row['row_count']];

            if ($includeBlobSizes) {
                $columns['blob_size'] = $row['blob_size'] ?? 0;
                $columns['name_size'] = $row['name_size'] ?? 0;
            }

            $serializedRows[] = [0 => $columns, 1 => [], 3 => null];
        }

        return serialize($serializedRows);
    }

    /** @return list<CachedRow>|null */
    private function decode(?string $value, bool $includeBlobSizes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);

        if (! is_array($decoded)) {
            return null;
        }

        $rows = [];

        foreach ($decoded as $entry) {
            $columns = is_array($entry) && isset($entry[0]) && is_array($entry[0]) ? $entry[0] : null;
            $label = $columns['label'] ?? null;
            $rowCount = $columns['row_count'] ?? null;

            $rowCount = $this->cachedInteger($rowCount);

            if (! is_string($label) || $rowCount === null) {
                return null;
            }

            $row = ['label' => $label, 'row_count' => $rowCount];

            if ($includeBlobSizes) {
                $blobSize = $columns['blob_size'] ?? null;
                $nameSize = $columns['name_size'] ?? null;

                $blobSize = $this->cachedInteger($blobSize);
                $nameSize = $this->cachedInteger($nameSize);

                if ($blobSize === null || $nameSize === null) {
                    return null;
                }

                $row['blob_size'] = $blobSize;
                $row['name_size'] = $nameSize;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function cachedInteger(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value !== (float) (int) $value) {
            return null;
        }

        return (int) $value;
    }

    private function storage(): ArchiveStorageRepository
    {
        return ($this->storage)();
    }

    private function options(): MutableOptionRepository
    {
        return ($this->options)();
    }
}
