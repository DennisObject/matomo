<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface AccessMetadataProvider
{
    /** @return list<array{id: string, name: string, description: string, helpUrl: string}> */
    public function roles(string $language): array;

    /**
     * @return list<array{id: string, name: string, description: string, helpUrl: string, includedInRoles: list<string>, category: string}>
     */
    public function capabilities(): array;
}
