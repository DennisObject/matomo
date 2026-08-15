<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

final readonly class PluginSettingDefinition
{
    /** @param array<string, string> $availableValues */
    public function __construct(
        public string $pluginName,
        public string $name,
        public string $title,
        public mixed $defaultValue = null,
        public string $type = 'string',
        public string $uiControl = 'text',
        public array $availableValues = [],
        public string $description = '',
        public string $inlineHelp = '',
        public string $introduction = '',
        public string $condition = '',
        public bool $fullWidth = false,
        public ?string $component = null,
    ) {}
}
