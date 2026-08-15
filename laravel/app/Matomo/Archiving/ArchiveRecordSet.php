<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use InvalidArgumentException;

final class ArchiveRecordSet
{
    /** @var array<string, int|float> */
    private array $numeric = [];

    /** @var array<string, string> */
    private array $blobs = [];

    public function addNumeric(string $name, int|float $value): void
    {
        $this->validateName($name);

        if (! is_finite((float) $value)) {
            throw new InvalidArgumentException("Archive record '{$name}' must contain a finite number.");
        }

        $this->numeric[$name] = $value;
        unset($this->blobs[$name]);
    }

    public function addBlob(string $name, string $value): void
    {
        $this->validateName($name);
        $this->blobs[$name] = $value;
        unset($this->numeric[$name]);
    }

    /** @return array<string, int|float> */
    public function numeric(): array
    {
        return $this->numeric;
    }

    /** @return array<string, string> */
    public function blobs(): array
    {
        return $this->blobs;
    }

    public function isEmpty(): bool
    {
        return $this->numeric === [] && $this->blobs === [];
    }

    private function validateName(string $name): void
    {
        if ($name === '' || strlen($name) > 190 || str_starts_with($name, 'done')) {
            throw new InvalidArgumentException("Archive record name '{$name}' is not valid.");
        }
    }
}
