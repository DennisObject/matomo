<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredPluginState implements PluginState
{
    public function __construct(private InstallationConfig $installation) {}

    public function isActivated(string $pluginName): bool
    {
        return in_array($pluginName, $this->installation->activatedPlugins(), true);
    }
}
