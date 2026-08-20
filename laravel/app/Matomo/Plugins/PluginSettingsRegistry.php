<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

interface PluginSettingsRegistry
{
    /** @return list<PluginSettingDefinition> */
    public function system(): array;

    /** @return list<PluginSettingDefinition> */
    public function user(): array;
}
