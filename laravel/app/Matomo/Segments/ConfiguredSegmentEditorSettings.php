<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Options\OptionRepository;
use Closure;

final readonly class ConfiguredSegmentEditorSettings implements SegmentEditorSettings
{
    /** @param Closure(): InstallationConfig $configuration */
    public function __construct(
        private Closure $configuration,
        private OptionRepository $options,
    ) {}

    public function allSitesAllowed(): bool
    {
        return $this->configuration()->segmentAllSitesAllowed();
    }

    public function realtimeAllowed(): bool
    {
        return $this->configuration()->realtimeSegmentsAllowed();
    }

    public function browserTriggerEnabled(): bool
    {
        $stored = $this->options->value('enableBrowserTriggerArchiving');

        return $stored === null
            ? $this->configuration()->browserArchivingTriggerEnabled()
            : (bool) $stored;
    }

    public function browserArchivingAvailable(): bool
    {
        return $this->configuration()->browserArchivingAvailableForSegments();
    }

    public function processNewSegmentsFrom(): string
    {
        return $this->configuration()->processNewSegmentsFrom();
    }

    private function configuration(): InstallationConfig
    {
        return ($this->configuration)();
    }
}
