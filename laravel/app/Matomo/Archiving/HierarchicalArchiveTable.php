<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use InvalidArgumentException;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type Columns array<string, ArchiveValue>
 * @phpstan-type ColumnRows array<string, Columns>
 * @phpstan-type TableRow array{columns: Columns, children: ColumnRows}
 * @phpstan-type TableRows array<string, TableRow>
 */
final class HierarchicalArchiveTable
{
    /** @var TableRows */
    private array $rows = [];

    /** @param array<string, 'max'|'min'|'skip'> $aggregationOperations */
    public function __construct(private readonly array $aggregationOperations = []) {}

    /** @param Columns $columns */
    public function mergeRoot(float|int|string $label, array $columns): void
    {
        $key = $this->rowKey($label);
        $new = ! isset($this->rows[$key]);
        $this->rows[$key] ??= ['columns' => ['label' => $label], 'children' => []];

        if ($new && ! $this->isSummary($label)) {
            $this->copySkippedMetrics($this->rows[$key]['columns'], $columns);
        }

        $this->mergeMetrics($this->rows[$key]['columns'], $columns);
    }

    public function hasRoot(float|int|string $label): bool
    {
        return isset($this->rows[$this->rowKey($label)]);
    }

    /** @param Columns $columns */
    public function mergeChild(
        float|int|string $rootLabel,
        float|int|string $label,
        array $columns,
    ): bool {
        $rootKey = $this->rowKey($rootLabel);

        if (! isset($this->rows[$rootKey])) {
            return false;
        }

        $key = $this->rowKey($label);
        $new = ! isset($this->rows[$rootKey]['children'][$key]);
        $this->rows[$rootKey]['children'][$key] ??= ['label' => $label];

        if ($new && ! $this->isSummary($label)) {
            $this->copySkippedMetrics(
                $this->rows[$rootKey]['children'][$key],
                $columns,
            );
        }

        $this->mergeMetrics($this->rows[$rootKey]['children'][$key], $columns);

        return true;
    }

    /**
     * @return array<string, string> Map of the empty root suffix and numeric subtable suffixes to serialized blobs.
     */
    public function serialized(int $rootLimit, int $subtableLimit, string $sortColumn): array
    {
        if ($rootLimit <= 0 || $subtableLimit <= 0 || $sortColumn === '') {
            throw new InvalidArgumentException('Archive table limits and its sort column must be set.');
        }

        $rows = $this->truncateRows($this->rows, $rootLimit, $sortColumn);
        $root = [];
        $serialized = [];
        $subtableId = 0;

        foreach ($rows as $row) {
            $children = $this->truncateColumnRows(
                $row['children'],
                $subtableLimit,
                $sortColumn,
            );
            $id = null;

            if ($children !== []) {
                $id = ++$subtableId;
                $serialized['_'.$id] = serialize(array_map(
                    static fn (array $columns): array => [
                        0 => $columns,
                        1 => [],
                        3 => null,
                    ],
                    array_values($children),
                ));
            }

            $root[] = [
                0 => $row['columns'],
                1 => [],
                3 => $id,
            ];
        }

        return ['' => serialize($root), ...$serialized];
    }

    /** @param TableRows $rows
     * @return TableRows
     */
    private function truncateRows(array $rows, int $limit, string $sortColumn): array
    {
        if (count($rows) <= $limit) {
            return $rows;
        }

        $summary = null;

        foreach ($rows as $key => $row) {
            if ($this->isSummary($row['columns']['label'] ?? null)) {
                $summary = $row['columns'];
                unset($rows[$key]);
            }
        }

        uasort(
            $rows,
            fn (array $left, array $right): int => $this->columnsSorter(
                $left['columns'],
                $right['columns'],
                $sortColumn,
            ),
        );
        $kept = array_slice($rows, 0, $limit - 1, true);
        $remainder = array_slice($rows, $limit - 1, null, true);
        $summary ??= ['label' => -1];

        foreach ($remainder as $row) {
            $this->mergeMetrics($summary, $row['columns']);
        }

        $kept[$this->rowKey(-1)] = ['columns' => $summary, 'children' => []];

        return $kept;
    }

    /** @param ColumnRows $rows
     * @return ColumnRows
     */
    private function truncateColumnRows(array $rows, int $limit, string $sortColumn): array
    {
        if (count($rows) <= $limit) {
            return $rows;
        }

        $summary = null;

        foreach ($rows as $key => $row) {
            if ($this->isSummary($row['label'] ?? null)) {
                $summary = $row;
                unset($rows[$key]);
            }
        }

        uasort(
            $rows,
            fn (array $left, array $right): int => $this->columnsSorter(
                $left,
                $right,
                $sortColumn,
            ),
        );
        $kept = array_slice($rows, 0, $limit - 1, true);
        $remainder = array_slice($rows, $limit - 1, null, true);
        $summary ??= ['label' => -1];

        foreach ($remainder as $row) {
            $this->mergeMetrics($summary, $row);
        }

        $kept[$this->rowKey(-1)] = $summary;

        return $kept;
    }

    /** @param Columns $left
     * @param  Columns  $right
     */
    private function columnsSorter(array $left, array $right, string $sortColumn): int
    {
        $metric = (float) ($right[$sortColumn] ?? 0) <=> (float) ($left[$sortColumn] ?? 0);

        return $metric !== 0
            ? $metric
            : strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    }

    /** @param Columns $target
     * @param  Columns  $columns
     */
    private function mergeMetrics(array &$target, array $columns): void
    {
        foreach ($columns as $metric => $value) {
            if ($metric === 'label') {
                continue;
            }

            $operation = $this->aggregationOperations[$metric] ?? 'sum';

            if ($operation === 'skip') {
                continue;
            }

            if ($operation === 'min') {
                $target[$metric] = $this->minimum($target[$metric] ?? null, $value);

                continue;
            }

            if ($operation === 'max') {
                $target[$metric] = $this->maximum($target[$metric] ?? null, $value);

                continue;
            }

            if (is_float($value) || is_int($value)) {
                $target[$metric] = $this->numeric(
                    (float) ($target[$metric] ?? 0) + $value,
                );
            }
        }
    }

    /** @param Columns $target
     * @param  Columns  $columns
     */
    private function copySkippedMetrics(array &$target, array $columns): void
    {
        foreach ($this->aggregationOperations as $metric => $operation) {
            $value = $columns[$metric] ?? null;

            if ($operation === 'skip' && (is_float($value) || is_int($value))) {
                $target[$metric] = $value;
            }
        }
    }

    private function isSummary(mixed $label): bool
    {
        return in_array($label, [-1, '-1'], true);
    }

    private function rowKey(float|int|string $label): string
    {
        return get_debug_type($label).':'.$label;
    }

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = round((float) $value, 2);

        return floor($number) === $number ? (int) $number : $number;
    }

    private function minimum(mixed $left, mixed $right): int|float|null
    {
        $left = is_numeric($left) ? $this->numeric($left) : null;
        $right = is_numeric($right) ? $this->numeric($right) : null;

        if ($left === null) {
            return $right;
        }

        return $right === null ? $left : min($left, $right);
    }

    private function maximum(mixed $left, mixed $right): int|float|null
    {
        $left = is_numeric($left) ? $this->numeric($left) : null;
        $right = is_numeric($right) ? $this->numeric($right) : null;

        if ($left === null) {
            return $right;
        }

        return $right === null ? $left : max($left, $right);
    }
}
