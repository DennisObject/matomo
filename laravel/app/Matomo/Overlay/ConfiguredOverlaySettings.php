<?php

declare(strict_types=1);

namespace App\Matomo\Overlay;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredOverlaySettings implements OverlaySettings
{
    public function __construct(private InstallationConfig $configuration) {}

    public function followingPagesLimit(): int
    {
        return $this->configuration->overlayFollowingPagesLimit();
    }

    public function urlQueryParametersToExclude(): array
    {
        return $this->configuration->urlQueryParametersToExclude();
    }

    public function campaignNameParameters(): array
    {
        return $this->configuration->campaignNameParameters();
    }

    public function campaignKeywordParameters(): array
    {
        return $this->configuration->campaignKeywordParameters();
    }

    public function pageMaximumLength(): int
    {
        return $this->configuration->pageMaximumLength();
    }
}
