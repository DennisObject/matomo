<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class MobileMessagingRequest
{
    /** @param array<string, string|int|null> $credentials */
    public function __construct(
        public ?string $provider = null,
        #[\SensitiveParameter]
        public array $credentials = [],
        public ?string $phoneNumber = null,
        #[\SensitiveParameter]
        public ?string $verificationCode = null,
        public ?bool $delegatedManagement = null,
    ) {}
}
