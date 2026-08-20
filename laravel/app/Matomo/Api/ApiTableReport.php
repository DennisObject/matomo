<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ApiTableReport
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<'idSite'|'date'>  $dimensions
     */
    public function __construct(
        public array $data,
        public array $dimensions,
    ) {}

    public function isMapped(): bool
    {
        return $this->dimensions !== [];
    }

    /** @return list<array<string, float|int|string|null>> */
    public function flattenedRows(): array
    {
        return $this->flatten($this->data, 0, []);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, float|int|string|null>  $dimensions
     * @return list<array<string, float|int|string|null>>
     */
    private function flatten(array $data, int $depth, array $dimensions): array
    {
        if (! isset($this->dimensions[$depth])) {
            $rows = [];

            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $values = [];

                foreach ($row as $name => $value) {
                    if (is_string($name)
                        && (is_float($value) || is_int($value) || is_string($value) || $value === null)) {
                        $values[$name] = $value;
                    }
                }

                $rows[] = [...$dimensions, ...$values];
            }

            return $rows;
        }

        $name = $this->dimensions[$depth];
        $rows = [];

        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $rows = [
                ...$rows,
                ...$this->flatten(
                    $value,
                    $depth + 1,
                    [...$dimensions, $name => $name === 'idSite' ? (int) $key : (string) $key],
                ),
            ];
        }

        return $rows;
    }
}
