<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ApiMetricReport
{
    /**
     * @param  array<array-key, mixed>|float|int|string|null  $data
     * @param  list<'idSite'|'date'>  $dimensions
     */
    public function __construct(
        public array|float|int|string|null $data,
        public array $dimensions,
    ) {}

    public static function fromReport(ApiReport $report, string $metric): self
    {
        return new self(
            self::values($report->data, $report->dimensions, $metric, 0),
            $report->dimensions,
        );
    }

    public function isMapped(): bool
    {
        return $this->dimensions !== [];
    }

    /**
     * @return list<array<string, float|int|string|null>>
     */
    public function flattenedRows(): array
    {
        if (! is_array($this->data)) {
            return [['value' => $this->data]];
        }

        return $this->flatten($this->data, 0, []);
    }

    /**
     * @param  callable(float|int|string|null): (float|int|string|null)  $formatter
     */
    public function map(callable $formatter): self
    {
        return new self($this->mapValues($this->data, $formatter), $this->dimensions);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<'idSite'|'date'>  $dimensions
     * @return array<array-key, mixed>|float|int|string
     */
    private static function values(array $data, array $dimensions, string $metric, int $depth): array|float|int|string
    {
        if (! isset($dimensions[$depth])) {
            $value = $data[$metric] ?? 0;

            return is_float($value) || is_int($value) || is_string($value)
                ? $value
                : 0;
        }

        $values = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::values($value, $dimensions, $metric, $depth + 1);
            }
        }

        return $values;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, float|int|string|null>  $dimensions
     * @return list<array<string, float|int|string|null>>
     */
    private function flatten(array $data, int $depth, array $dimensions): array
    {
        $name = $this->dimensions[$depth];
        $leaf = ! isset($this->dimensions[$depth + 1]);
        $rows = [];

        foreach ($data as $key => $value) {
            $rowDimensions = [
                ...$dimensions,
                $name => $name === 'idSite' ? (int) $key : (string) $key,
            ];

            if ($leaf) {
                $rows[] = [...$rowDimensions, 'value' => $this->scalar($value)];
            } elseif (is_array($value)) {
                $rows = [...$rows, ...$this->flatten($value, $depth + 1, $rowDimensions)];
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>|float|int|string|null  $data
     * @param  callable(float|int|string|null): (float|int|string|null)  $formatter
     * @return array<array-key, mixed>|float|int|string|null
     */
    private function mapValues(array|float|int|string|null $data, callable $formatter): array|float|int|string|null
    {
        if (! is_array($data)) {
            return $formatter($data);
        }

        $values = [];

        foreach ($data as $key => $value) {
            if (is_array($value) || is_float($value) || is_int($value) || is_string($value) || $value === null) {
                $values[$key] = $this->mapValues($value, $formatter);
            }
        }

        return $values;
    }

    private function scalar(mixed $value): float|int|string|null
    {
        return is_float($value) || is_int($value) || is_string($value) || $value === null
            ? $value
            : null;
    }
}
