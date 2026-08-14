<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

interface PluginState
{
    public function isActivated(string $pluginName): bool;
}
