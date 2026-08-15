<?php

declare(strict_types=1);

namespace App\Matomo\Insights;

use InvalidArgumentException;

final class InsightRowComparison
{
    public const string ORDER_ABSOLUTE = 'absolute';

    public const string ORDER_RELATIVE = 'relative';

    public const string ORDER_IMPORTANCE = 'importance';

    /**
     * @param  list<array<string, mixed>>  $currentRows
     * @param  list<array<string, mixed>>  $pastRows
     * @return list<array<string, mixed>>
     */
    public function compare(
        array $currentRows,
        array $pastRows,
        string $metric,
        int $totalValue,
        int $minMoversPercent,
        int $minNewPercent,
        int $minDisappearedPercent,
        float $minGrowthPositive,
        float $minGrowthNegative,
        string $orderBy,
        int $limitIncreaser,
        int $limitDecreaser,
    ): array {
        $orderColumns = match ($orderBy) {
            self::ORDER_ABSOLUTE => ['difference', 'growth_percent_numeric'],
            self::ORDER_RELATIVE => ['growth_percent_numeric', 'difference'],
            self::ORDER_IMPORTANCE => ['importance', 'growth_percent_numeric'],
            default => throw new InvalidArgumentException('Unsupported orderBy'),
        };
        $pastByLabel = $this->byLabel($pastRows);
        $currentByLabel = $this->byLabel($currentRows);
        $rows = [];

        foreach ($currentRows as $row) {
            $label = $this->label($row);
            $past = $pastByLabel[$label] ?? null;
            $isNew = $past === null;

            if (($isNew && $minNewPercent === -1) || (! $isNew && $minMoversPercent === -1)) {
                continue;
            }

            $rows[] = $this->resultRow(
                $row,
                $this->metric($row, $metric),
                $past === null ? 0.0 : $this->metric($past, $metric),
                $isNew,
                ! $isNew,
                false,
            );
        }

        if ($minDisappearedPercent !== -1) {
            foreach ($pastRows as $row) {
                if (isset($currentByLabel[$this->label($row)])) {
                    continue;
                }

                $rows[] = $this->resultRow(
                    $row,
                    0.0,
                    $this->metric($row, $metric),
                    false,
                    false,
                    true,
                );
            }
        }

        $minimums = [
            'isMover' => $this->minimumValue($totalValue, $minMoversPercent),
            'isNew' => $this->minimumValue($totalValue, $minNewPercent),
            'isDisappeared' => $this->minimumValue($totalValue, $minDisappearedPercent),
        ];
        $rows = array_values(array_filter(
            $rows,
            static function (array $row) use ($minimums, $minGrowthPositive, $minGrowthNegative): bool {
                $growth = (float) $row['growth_percent_numeric'];

                if (($growth >= 0 && $growth < $minGrowthPositive)
                    || ($growth < 0 && $growth > $minGrowthNegative)) {
                    return false;
                }

                foreach ($minimums as $flag => $minimum) {
                    if ($minimum > 0 && $row[$flag] === true && abs((float) $row['difference']) < $minimum) {
                        return false;
                    }
                }

                return true;
            },
        ));

        usort($rows, fn (array $left, array $right): int => $this->compareRows($left, $right, [
            ...$orderColumns,
            $metric,
        ]));

        $increasers = 0;
        $decreasers = 0;

        return array_values(array_filter($rows, static function (array $row) use (
            $limitIncreaser,
            $limitDecreaser,
            &$increasers,
            &$decreasers,
        ): bool {
            if ((float) $row['growth_percent_numeric'] >= 0) {
                $increasers++;

                return $limitIncreaser < 0 || $increasers <= $limitIncreaser;
            }

            $decreasers++;

            return $limitDecreaser < 0 || $decreasers <= $limitDecreaser;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byLabel(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$this->label($row)] = $row;
        }

        return $indexed;
    }

    /** @param array<string, mixed> $row */
    private function label(array $row): string
    {
        $label = $row['label'] ?? null;

        if (! is_string($label) && ! is_int($label) && ! is_float($label)) {
            throw new InvalidArgumentException('Insight report rows must have a scalar label.');
        }

        return (string) $label;
    }

    /** @param array<string, mixed> $row */
    private function metric(array $row, string $metric): float
    {
        $value = $row[$metric] ?? 0;

        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resultRow(
        array $row,
        float $newValue,
        float $oldValue,
        bool $isNew,
        bool $isMover,
        bool $isDisappeared,
    ): array {
        $difference = $newValue - $oldValue;
        $growth = match (true) {
            $newValue === 0.0 && $oldValue === 0.0 => 0.0,
            $oldValue === 0.0 => 100.0,
            default => round(($difference / $oldValue) * 100, 1),
        };
        $growthText = $this->number($growth).'%';

        return [
            ...$row,
            'growth_percent' => $growthText,
            'growth_percent_numeric' => $this->number($growth),
            'grown' => $growth >= 0,
            'value_old' => $this->integerWhenWhole($oldValue),
            'value_new' => $this->integerWhenWhole($newValue),
            'difference' => $this->integerWhenWhole($difference),
            'importance' => $this->integerWhenWhole(abs($difference)),
            'isDisappeared' => $isDisappeared,
            'isNew' => $isNew,
            'isMover' => $isMover,
        ];
    }

    private function minimumValue(int $totalValue, int $percent): int
    {
        return $percent <= 0 ? 0 : (int) ceil(($totalValue / 100) * $percent);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  list<string>  $columns
     */
    private function compareRows(array $left, array $right, array $columns): int
    {
        foreach ($columns as $column) {
            $leftValue = (float) ($left[$column] ?? 0);
            $rightValue = (float) ($right[$column] ?? 0);

            if ($leftValue === $rightValue) {
                continue;
            }

            if ($leftValue >= 0 && $rightValue < 0) {
                return -1;
            }

            if ($leftValue < 0 && $rightValue >= 0) {
                return 1;
            }

            if ($leftValue < 0) {
                return $leftValue < $rightValue ? -1 : 1;
            }

            return $leftValue > $rightValue ? -1 : 1;
        }

        return 0;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private function integerWhenWhole(float $value): float|int
    {
        return floor($value) === $value ? (int) $value : $value;
    }
}
