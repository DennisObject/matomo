<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

final readonly class ConfiguredPluginSettingsRegistry implements PluginSettingsRegistry
{
    /**
     * @param  array<mixed>  $system
     * @param  array<mixed>  $user
     */
    public function __construct(private array $system, private array $user) {}

    public function system(): array
    {
        return $this->definitions($this->system);
    }

    public function user(): array
    {
        return $this->definitions($this->user);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<PluginSettingDefinition>
     */
    private function definitions(array $values): array
    {
        $definitions = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            $plugin = $value['pluginName'] ?? null;
            $name = $value['name'] ?? null;
            if (! is_string($plugin) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $plugin) !== 1
                || ! is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $name) !== 1) {
                continue;
            }

            $available = $value['availableValues'] ?? [];
            $definitions[] = new PluginSettingDefinition(
                pluginName: $plugin,
                name: $name,
                title: is_string($value['title'] ?? null) ? $value['title'] : $name,
                defaultValue: $value['defaultValue'] ?? null,
                type: is_string($value['type'] ?? null) ? $value['type'] : 'string',
                uiControl: is_string($value['uiControl'] ?? null) ? $value['uiControl'] : 'text',
                availableValues: is_array($available) ? $available : [],
                description: is_string($value['description'] ?? null) ? $value['description'] : '',
                inlineHelp: is_string($value['inlineHelp'] ?? null) ? $value['inlineHelp'] : '',
                introduction: is_string($value['introduction'] ?? null) ? $value['introduction'] : '',
                condition: is_string($value['condition'] ?? null) ? $value['condition'] : '',
                fullWidth: (bool) ($value['fullWidth'] ?? false),
                component: is_string($value['component'] ?? null) ? $value['component'] : null,
            );
        }

        return $definitions;
    }
}
