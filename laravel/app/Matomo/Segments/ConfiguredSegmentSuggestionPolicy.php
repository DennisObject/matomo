<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Config\InstallationConfig;
use Closure;

final readonly class ConfiguredSegmentSuggestionPolicy implements SegmentSuggestionPolicy
{
    /** @param Closure(): InstallationConfig $configuration */
    public function __construct(private Closure $configuration) {}

    public function enabled(): bool
    {
        return ($this->configuration)()->segmentSuggestedValuesEnabled();
    }
}
