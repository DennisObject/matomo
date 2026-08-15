<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

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

    public function filteredForUser(
        string $login,
        ?int $limit,
        int $offset,
        ?string $search,
        ?string $access,
        ?array $allowedSiteIds,
    ): array {
        $query = $this->filteredSites($login, $search, $access, $allowedSiteIds);
        $total = (clone $query)->distinct()->count('site.idsite');
        $sites = $query->select(['site.idsite', 'site.name'])
            ->distinct()
            ->orderBy('site.name')
            ->orderBy('site.idsite');
        if ($limit !== null && $limit > 0) {
            $sites->limit($limit)->offset(max(0, $offset));
        }

        $siteRows = $sites->get();
        $siteIds = array_values(array_map(static fn (object $site): int => (int) $site->idsite, $siteRows->all()));
        $entries = [];
        if ($siteIds !== []) {
            $rows = $this->connection->table('access')
                ->select(['idsite', 'access'])
                ->where('login', $login)
                ->whereIn('idsite', $siteIds)
                ->get();
            foreach ($rows as $row) {
                if (is_numeric($row->idsite ?? null) && is_string($row->access ?? null)) {
                    $entries[(int) $row->idsite][] = $row->access;
                }
            }
        }

        return [
            'rows' => array_values(array_map(static fn (object $site): array => [
                'idsite' => (int) $site->idsite,
                'site_name' => (string) $site->name,
                'access' => $entries[(int) $site->idsite] ?? [],
            ], $siteRows->all())),
            'total' => $total,
            'hasSome' => $this->connection->table('access')->where('login', $login)->exists(),
        ];
    }

    /** @param list<int>|null $allowedSiteIds */
    private function filteredSites(
        string $login,
        ?string $search,
        ?string $access,
        ?array $allowedSiteIds,
    ): Builder {
        $query = $this->connection->table('site')->leftJoin(
            'access as user_access',
            static function (JoinClause $join) use ($login): void {
                $join->on('site.idsite', '=', 'user_access.idsite')->where('user_access.login', $login);
            },
        );
        if ($search !== null && $search !== '') {
            $query->where(static function (Builder $match) use ($search): void {
                $match->where('site.name', 'like', '%'.$search.'%')
                    ->orWhere('site.main_url', 'like', 'http%'.$search.'%')
                    ->orWhere('site.group', 'like', '%'.$search.'%');
            });
        }

        if ($access === 'noaccess') {
            $query->whereNull('user_access.access');
        } elseif ($access === 'some') {
            $query->whereNotNull('user_access.access');
        } elseif ($access !== null && $access !== '') {
            $query->where('user_access.access', $access);
        }

        if ($allowedSiteIds !== null) {
            $query->whereIn('site.idsite', $allowedSiteIds);
        }

        return $query;
    }
}
