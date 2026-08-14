<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

final readonly class LocalTrackerFileAvailability implements TrackerFileAvailability
{
    public function __construct(
        private string $sourcePath,
        private string $targetPath,
    ) {}

    public function canUpdate(): bool
    {
        if (! is_file($this->sourcePath) || ! is_readable($this->sourcePath)) {
            return false;
        }

        if (is_file($this->targetPath) && ! is_writable($this->targetPath)) {
            return false;
        }

        return is_writable($this->targetPath) || is_writable(dirname($this->targetPath));
    }
}
