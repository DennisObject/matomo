<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyPurgeExecutionRequest
{
    public function __construct(
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
