<?php

declare(strict_types=1);

namespace App\Matomo\MobileMessaging;

interface MobileMessagingSettingsRepository
{
    /** @return array<string, mixed> */
    public function read(string $login): array;

    /** @param array<string, mixed> $settings */
    public function save(string $login, #[\SensitiveParameter] array $settings): void;
}
