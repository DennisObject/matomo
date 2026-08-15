<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyComplianceStatusRequest
{
    public function __construct(
        public string $site,
        public string $policy,
        public bool $enforce,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
