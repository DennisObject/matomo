<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class CorePluginsAdminRequest
{
    /** @param array<string, mixed> $settingValues */
    public function __construct(
        public array $settingValues = [],
        #[\SensitiveParameter]
        public string $passwordConfirmation = '',
    ) {}
}
