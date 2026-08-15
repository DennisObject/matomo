<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use InvalidArgumentException;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type Columns array<string, ArchiveValue>
 * @phpstan-type Metadata array<string, ArchiveValue>
 * @phpstan-type ArchiveRow array{columns: Columns, metadata: Metadata, subtableId: int|null}
 * @phpstan-type ArchiveRecords array<string, list<ArchiveRow>>
 */
final class RecursiveArchiveTable
{
    /** @var array<string, RecursiveArchiveNode> */
    private array $rows = [];

    /**
     * @param  array<string, 'max'|'min'>  $aggregationOperations
     * @param  list<string>  $nonSummableParentColumns
     */
    public function __construct(
        private readonly array $aggregationOperations = [],
        private readonly array $nonSummableParentColumns = [],
    ) {}

    /**
     * @param  non-empty-list<float|int|string>  $path
     * @param  Columns  $columns
     * @param  Metadata  $metadata
     */
    public function mergePath(array $path, array $columns, array $metadata = []): void
    {
        $nodes = &$this->rows;

        foreach ($path as $label) {
            $key = $this->rowKey($label);
            $nodes[$key] ??= new RecursiveArchiveNode(['label' => $label]);
            $node = &$nodes[$key];
            $nodes = &$node->children;
        }

        $this->mergeColumns($node->columns, $columns);
        $node->metadata = [...$node->metadata, ...$metadata];
    }

    /**
     * @param  ArchiveRecords  $records
     * @param  array<string, string>  $columnRenames
     */
    public function mergeArchiveRecords(
        array $records,
        string $recordName,
        array $columnRenames = [],
    ): void {
        $this->mergeArchiveRows(
            $records[$recordName] ?? [],
            $records,
            $recordName,
            [],
            $columnRenames,
        );
    }

    /**
     * @return array<string, string> Map of the root suffix and numeric subtable suffixes to serialized blobs.
     */
    public function serialized(int $rootLimit, int $subtableLimit, string $sortColumn): array
    {
        if ($rootLimit <= 0 || $subtableLimit <= 0 || $sortColumn === '') {
            throw new InvalidArgumentException('Archive table limits and its sort column must be set.');
        }

        $serialized = [];
        $nextSubtableId = 0;
        $serialized[''] = serialize($this->serializedRows(
            $this->truncate($this->rows, $rootLimit, $sortColumn),
            $subtableLimit,
            $sortColumn,
            $serialized,
            $nextSubtableId,
        ));

        return $serialized;
    }

    /**
     * @param  list<ArchiveRow>  $rows
     * @param  ArchiveRecords  $records
     * @param  list<float|int|string>  $path
     * @param  array<string, string>  $columnRenames
     */
    private function mergeArchiveRows(
        array $rows,
        array $records,
        string $recordName,
        array $path,
        array $columnRenames,
    ): void {
        foreach ($rows as $row) {
            $label = $row['columns']['label'] ?? null;

            if (! is_float($label) && ! is_int($label) && ! is_string($label)) {
                continue;
            }

            $rowPath = [...$path, $label];
            $subtableId = $row['subtableId'];
            $subtable = $subtableId === null
                ? []
                : ($records[$recordName.'_'.$subtableId] ?? []);

            if ($subtable !== []) {
                $this->mergeArchiveRows(
                    $subtable,
                    $records,
                    $recordName,
                    $rowPath,
                    $columnRenames,
                );

                continue;
            }

            $columns = [];

            foreach ($row['columns'] as $name => $value) {
                $columns[$columnRenames[$name] ?? $name] = $value;
            }

            $this->mergePath($rowPath, $columns, $row['metadata']);
        }
    }

    /**
     * @param  array<string, RecursiveArchiveNode>  $nodes
     * @param  array<string, string>  $serialized
     * @return list<array{0: Columns, 1: Metadata, 3: int|null}>
     */
    private function serializedRows(
        array $nodes,
        int $subtableLimit,
        string $sortColumn,
        array &$serialized,
        int &$nextSubtableId,
    ): array {
        $rows = [];

        foreach ($nodes as $node) {
            $children = $this->truncate($node->children, $subtableLimit, $sortColumn);
            $subtableId = null;

            if ($children !== []) {
                $subtableId = ++$nextSubtableId;
                $subtableRows = $this->serializedRows(
                    $children,
                    $subtableLimit,
                    $sortColumn,
                    $serialized,
                    $nextSubtableId,
                );
                $serialized['_'.$subtableId] = serialize($subtableRows);
            }

            $columns = $children === []
                ? $node->columns
                : $this->parentColumns($node->columns['label'], $children);
            $rows[] = [
                0 => $columns,
                1 => $subtableId === null ? $node->metadata : [],
                3 => $subtableId,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, RecursiveArchiveNode>  $nodes
     * @return array<string, RecursiveArchiveNode>
     */
    private function truncate(array $nodes, int $limit, string $sortColumn): array
    {
        if (count($nodes) <= $limit) {
            return $nodes;
        }

        $summary = null;

        foreach ($nodes as $key => $node) {
            if ($this->isSummary($node->columns['label'] ?? null)) {
                $summary = $node;
                unset($nodes[$key]);
            }
        }

        uasort(
            $nodes,
            fn (RecursiveArchiveNode $left, RecursiveArchiveNode $right): int => $this->nodeSorter(
                $left,
                $right,
                $sortColumn,
            ),
        );
        $kept = array_slice($nodes, 0, $limit - 1, true);
        $remainder = array_slice($nodes, $limit - 1, null, true);
        $summary ??= new RecursiveArchiveNode(['label' => -1]);

        foreach ($remainder as $node) {
            $this->mergeColumns(
                $summary->columns,
                $this->effectiveColumns($node),
            );
        }

        $kept[$this->rowKey(-1)] = $summary;

        return $kept;
    }

    private function nodeSorter(
        RecursiveArchiveNode $left,
        RecursiveArchiveNode $right,
        string $sortColumn,
    ): int {
        $leftColumns = $this->effectiveColumns($left);
        $rightColumns = $this->effectiveColumns($right);
        $metric = (float) ($rightColumns[$sortColumn] ?? 0)
            <=> (float) ($leftColumns[$sortColumn] ?? 0);

        return $metric !== 0
            ? $metric
            : strnatcasecmp(
                (string) ($left->columns['label'] ?? ''),
                (string) ($right->columns['label'] ?? ''),
            );
    }

    /** @return Columns */
    private function effectiveColumns(RecursiveArchiveNode $node): array
    {
        return $node->children === []
            ? $node->columns
            : $this->parentColumns($node->columns['label'], $node->children);
    }

    /**
     * @param  ArchiveValue  $label
     * @param  array<string, RecursiveArchiveNode>  $children
     * @return Columns
     */
    private function parentColumns(float|int|string|null $label, array $children): array
    {
        $columns = ['label' => $label];

        foreach ($children as $child) {
            $this->mergeColumns($columns, $this->effectiveColumns($child));
        }

        foreach ($this->nonSummableParentColumns as $name) {
            unset($columns[$name]);
        }

        return $columns;
    }

    /** @param Columns $target
     * @param  Columns  $columns
     */
    private function mergeColumns(array &$target, array $columns): void
    {
        foreach ($columns as $name => $value) {
            if ($name === 'label') {
                continue;
            }

            $operation = $this->aggregationOperations[$name] ?? 'sum';

            if ($operation === 'min') {
                $target[$name] = $this->minimum($target[$name] ?? null, $value);

                continue;
            }

            if ($operation === 'max') {
                $target[$name] = $this->maximum($target[$name] ?? null, $value);

                continue;
            }

            if (is_float($value) || is_int($value)) {
                $target[$name] = $this->numeric((float) ($target[$name] ?? 0) + $value);
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
