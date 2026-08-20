<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

interface AiProviderSettingsRepository
{
    public function read(): AiProviderStoredSettings;

    public function save(#[\SensitiveParameter] AiProviderStoredSettings $settings): void;
}
