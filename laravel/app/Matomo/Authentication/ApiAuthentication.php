<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

final readonly class ApiAuthentication
{
    public function __construct(
        #[\SensitiveParameter]
        public ?string $token,
        public bool $tokenIsSecure,
        public bool $forceSession,
        #[\SensitiveParameter]
        public ?string $sessionId,
    ) {}
}
