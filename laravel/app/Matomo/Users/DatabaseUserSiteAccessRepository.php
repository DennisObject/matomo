<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseUserSiteAccessRepository implements UserSiteAccessRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function sitesByLogin(string $access): array
    {
        $result = [];
        $rows = $this->connection->table('access')
            ->select(['login', 'idsite'])
            ->where('access', $access)
            ->orderBy('login')
            ->orderBy('idsite')
            ->get();
        foreach ($rows as $row) {
            if (is_string($row->login ?? null) && is_numeric($row->idsite ?? null)) {
                $result[$row->login][] = (int) $row->idsite;
            }
        }

        return $result;
    }

    public function accessByLogin(int $siteId): array
    {
        $result = [];
        $rows = $this->connection->table('access')
            ->select(['login', 'access'])
            ->where('idsite', $siteId)
            ->get();
        foreach ($rows as $row) {
            if (is_string($row->login ?? null) && is_string($row->access ?? null)) {
                $result[$row->login] = $row->access;
            }
        }

        return $result;
    }

    public function logins(int $siteId, string $access): array
    {
        return array_values(array_filter(
            $this->connection->table('access')
                ->where('idsite', $siteId)
                ->where('access', $access)
                ->pluck('login')
                ->all(),
            is_string(...),
        ));
    }

    public function forUser(string $login): array
    {
        $rows = $this->connection->table('access')
            ->join('site', 'access.idsite', '=', 'site.idsite')
            ->select(['access.idsite', 'access.access'])
            ->where('access.login', $login)
            ->get();

        return array_values(array_filter(array_map(
            static fn (object $row): ?array => is_numeric($row->idsite ?? null)
                && is_string($row->access ?? null)
                ? ['site' => (int) $row->idsite, 'access' => $row->access]
                : null,
            $rows->all(),
        )));
    }
}
