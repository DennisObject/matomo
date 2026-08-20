<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserDirectoryRepository
{
    /** @param list<string> $logins
     * @return list<array<string, mixed>>
     */
    public function users(array $logins = []): array;

    /** @return list<string> */
    public function logins(): array;

    /** @return array<string, mixed>|null */
    public function user(string $login): ?array;

    /** @return array<string, mixed>|null */
    public function userByEmail(string $email): ?array;

    /** @return list<array<string, mixed>> */
    public function superusers(): array;

    /** @param list<int> $adminSiteIds
     * @return list<string>
     */
    public function visibleLogins(string $currentLogin, array $adminSiteIds): array;
}
