<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

final readonly class PluginSettingsManager
{
    public function __construct(
        private PluginSettingsRegistry $registry,
        private PluginSettingsStore $store,
    ) {}

    /** @return list<array<string, mixed>> */
    public function system(): array
    {
        return $this->formatted($this->registry->system(), '');
    }

    /** @return list<array<string, mixed>> */
    public function user(string $login): array
    {
        return $this->formatted($this->registry->user(), $login);
    }

    /** @param array<string, mixed> $submitted */
    public function setSystem(array $submitted): void
    {
        $this->save($this->registry->system(), '', $submitted);
    }

    /** @param array<string, mixed> $submitted */
    public function setUser(string $login, array $submitted): void
    {
        $this->save($this->registry->user(), $login, $submitted);
    }

    /**
     * @param  list<PluginSettingDefinition>  $definitions
     * @return list<array<string, mixed>>
     */
    private function formatted(array $definitions, string $login): array
    {
        $grouped = [];
        foreach ($definitions as $definition) {
            $stored = $this->store->values($definition->pluginName, $login);
            $value = $stored[$definition->name] ?? $definition->defaultValue;
            if ($definition->uiControl === 'password' && $value !== null && $value !== '') {
                $value = '******';
            }

            $setting = [
                'name' => $definition->name, 'title' => $definition->title, 'value' => $value,
                'defaultValue' => $definition->defaultValue, 'type' => $definition->type,
                'uiControl' => $definition->uiControl, 'uiControlAttributes' => [],
                'availableValues' => (object) $definition->availableValues,
                'description' => $definition->description, 'inlineHelp' => $definition->inlineHelp,
                'introduction' => $definition->introduction, 'condition' => $definition->condition,
                'fullWidth' => $definition->fullWidth,
            ];
            if ($definition->component !== null) {
                $setting['component'] = $definition->component;
            }

            $grouped[$definition->pluginName]['pluginName'] = $definition->pluginName;
            $grouped[$definition->pluginName]['title'] = $definition->pluginName;
            $grouped[$definition->pluginName]['settings'][] = $setting;
        }

        return array_values($grouped);
    }

    /**
     * @param  list<PluginSettingDefinition>  $definitions
     * @param  array<string, mixed>  $submitted
     */
    private function save(array $definitions, string $login, array $submitted): void
    {
        $byPlugin = [];
        foreach ($definitions as $definition) {
            $byPlugin[$definition->pluginName][$definition->name] = $definition;
        }

        foreach ($submitted as $plugin => $settings) {
            if (! isset($byPlugin[$plugin]) || ! is_array($settings)) {
                throw new PluginSettingsException('A plugin setting is not registered.');
            }

            $values = $this->store->values($plugin, $login);
            foreach ($settings as $setting) {
                if (! is_array($setting) || ! is_string($setting['name'] ?? null)
                    || ! isset($byPlugin[$plugin][$setting['name']])) {
                    throw new PluginSettingsException('A plugin setting is not registered.');
                }

                $definition = $byPlugin[$plugin][$setting['name']];
                $value = $setting['value'] ?? null;
                if ($definition->type === 'array' && $value === '__empty__') {
                    $value = [];
                }

                if ($definition->uiControl === 'password' && $value === '******') {
                    continue;
                }

                $values[$definition->name] = $this->validated($definition, $value);
            }

            $this->store->replace($plugin, $login, $values);
        }
    }

    private function validated(PluginSettingDefinition $definition, mixed $value): mixed
    {
        $value = match ($definition->type) {
            'array' => is_array($value) ? $value : throw new PluginSettingsException($definition->title.': Invalid value.'),
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                ?? throw new PluginSettingsException($definition->title.': Invalid value.'),
            'float' => is_numeric($value) ? (float) $value : throw new PluginSettingsException($definition->title.': Invalid value.'),
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (int) $value : throw new PluginSettingsException($definition->title.': Invalid value.'),
            default => is_scalar($value) || $value === null
                ? (string) ($value ?? '') : throw new PluginSettingsException($definition->title.': Invalid value.'),
        };
        if ($definition->availableValues !== []) {
            $key = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_float($value), is_int($value), is_string($value) => (string) $value,
                default => null,
            };
            if ($key === null || ! array_key_exists($key, $definition->availableValues)) {
                throw new PluginSettingsException($definition->title.': Invalid value.');
            }
        }

        return $value;
    }
}
