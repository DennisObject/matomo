<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyPurgeSettingsRequest
{
    /** @param array<string, int> $values */
    public function __construct(
        public array $values,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
