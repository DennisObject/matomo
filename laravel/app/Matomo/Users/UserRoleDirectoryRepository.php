<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserRoleDirectoryRepository
{
    /**
     * @param  list<string>|null  $allowedLogins
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function filtered(
        int $siteId,
        ?int $limit,
        int $offset,
        ?string $search,
        ?string $access,
        ?string $status,
        ?array $allowedLogins,
        string $currentLogin,
        bool $superuser,
    ): array;

    /** @return list<string> */
    public function accessEntries(string $login, int $siteId): array;
}
