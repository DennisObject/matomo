<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

interface PluginSettingsStore
{
    /** @return array<string, mixed> */
    public function values(string $pluginName, string $login): array;

    /** @param array<string, mixed> $values */
    public function replace(string $pluginName, string $login, array $values): void;
}
