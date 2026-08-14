<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

interface VersionAccessAuthorizer
{
    public function hasSomeViewAccess(
        #[\SensitiveParameter]
        ?string $token,
        bool $tokenIsSecure,
    ): bool;
}
