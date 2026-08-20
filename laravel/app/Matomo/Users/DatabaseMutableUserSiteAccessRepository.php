<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseMutableUserSiteAccessRepository implements MutableUserSiteAccessRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function replace(string $login, array $siteIds, ?string $role, array $capabilities): string
    {
        return $this->connection->transaction(function () use ($login, $siteIds, $role, $capabilities): string {
            $user = $this->connection->table('user')->where('login', $login)->lockForUpdate()->first();
            if ((int) ($user->superuser_access ?? 0) === 1) {
                return 'superuser';
            }

            $this->connection->table('access')->where('login', $login)->whereIn('idsite', $siteIds)->delete();
            $entries = $role === null ? [] : [$role, ...$capabilities];
            $this->insert($login, $siteIds, $entries);

            return 'updated';
        });
    }

    public function addCapabilities(string $login, array $siteIds, array $includedInRoles): int|string|null
    {
        return $this->connection->transaction(function () use ($login, $siteIds, $includedInRoles): int|string|null {
            $user = $this->connection->table('user')->where('login', $login)->lockForUpdate()->first();
            if ((int) ($user->superuser_access ?? 0) === 1) {
                return 'superuser';
            }

            $rows = $this->connection->table('access')
                ->select(['idsite', 'access'])
                ->where('login', $login)
                ->whereIn('idsite', $siteIds)
                ->get();
            $entries = [];
            foreach ($rows as $row) {
                if (is_numeric($row->idsite ?? null) && is_string($row->access ?? null)) {
                    $entries[(int) $row->idsite][] = $row->access;
                }
            }

            foreach ($siteIds as $siteId) {
                $role = array_values(array_intersect($entries[$siteId] ?? [], ['view', 'write', 'admin']))[0] ?? null;
                if ($role === null) {
                    return $siteId;
                }
            }

            foreach ($siteIds as $siteId) {
                $role = array_values(array_intersect($entries[$siteId], ['view', 'write', 'admin']))[0];
                foreach ($includedInRoles as $capability => $roles) {
                    if (in_array($capability, $entries[$siteId] ?? [], true)
                        || in_array($role, $roles, true)) {
                        continue;
                    }

                    $this->connection->table('access')->insert([
                        'login' => $login,
                        'idsite' => $siteId,
                        'access' => $capability,
                    ]);
                }
            }

            return null;
        });
    }

    public function removeCapabilities(string $login, array $siteIds, array $capabilities): void
    {
        $this->connection->transaction(function () use ($login, $siteIds, $capabilities): void {
            $this->connection->table('user')->where('login', $login)->lockForUpdate()->first();
            $this->connection->table('access')
                ->where('login', $login)
                ->whereIn('idsite', $siteIds)
                ->whereIn('access', $capabilities)
                ->delete();
        });
    }

    /** @param list<int> $siteIds
     * @param  list<string>  $entries
     */
    private function insert(string $login, array $siteIds, array $entries): void
    {
        $rows = [];
        foreach ($siteIds as $siteId) {
            foreach ($entries as $entry) {
                $rows[] = ['login' => $login, 'idsite' => $siteId, 'access' => $entry];
            }
        }

        if ($rows !== []) {
            $this->connection->table('access')->insert($rows);
        }
    }
}
