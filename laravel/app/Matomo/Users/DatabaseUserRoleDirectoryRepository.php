<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

final readonly class DatabaseUserRoleDirectoryRepository implements UserRoleDirectoryRepository
{
    public function __construct(private ConnectionInterface $connection) {}

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
    ): array {
        $query = $this->candidates(
            $siteId,
            $search,
            $access === 'superuser' && ! $superuser ? null : $access,
            $status,
            $allowedLogins,
            $currentLogin,
            $superuser,
        );
        $total = (clone $query)->distinct()->count('user.login');
        $candidateQuery = $query->select('user.login')->distinct()->orderBy('user.login');
        if ($limit !== null && $limit > 0) {
            $candidateQuery->limit($limit)->offset(max(0, $offset));
        }

        $logins = array_values(array_filter($candidateQuery->pluck('user.login')->all(), is_string(...)));
        if ($logins === []) {
            return ['rows' => [], 'total' => $total];
        }

        $users = array_values(array_map(
            get_object_vars(...),
            $this->connection->table('user')->whereIn('login', $logins)->orderBy('login')->get()->all(),
        ));
        $entries = [];
        $accessRows = $this->connection->table('access')
            ->select(['login', 'access'])
            ->where('idsite', $siteId)
            ->whereIn('login', $logins)
            ->get();
        foreach ($accessRows as $row) {
            if (is_string($row->login ?? null) && is_string($row->access ?? null)) {
                $entries[$row->login][] = $row->access;
            }
        }

        foreach ($users as &$user) {
            $login = $user['login'] ?? null;
            $user['access'] = is_string($login) ? ($entries[$login] ?? []) : [];
        }

        return ['rows' => $users, 'total' => $total];
    }

    public function accessEntries(string $login, int $siteId): array
    {
        return array_values(array_filter(
            $this->connection->table('access')
                ->where('login', $login)
                ->where('idsite', $siteId)
                ->pluck('access')
                ->all(),
            is_string(...),
        ));
    }

    /** @param list<string>|null $allowedLogins */
    private function candidates(
        int $siteId,
        ?string $search,
        ?string $access,
        ?string $status,
        ?array $allowedLogins,
        string $currentLogin,
        bool $superuser,
    ): Builder {
        $query = $this->connection->table('user')->leftJoin(
            'access as site_access',
            static function (JoinClause $join) use ($siteId): void {
                $join->on('user.login', '=', 'site_access.login')->where('site_access.idsite', $siteId);
            },
        );
        if ($access === 'noaccess') {
            $query->whereNull('site_access.access')->where('user.superuser_access', '<>', 1);
        } elseif ($access === 'some') {
            $query->where(static function (Builder $some): void {
                $some->whereNotNull('site_access.access')->orWhere('user.superuser_access', 1);
            });
        } elseif ($access === 'superuser') {
            $query->where('user.superuser_access', 1);
        } elseif ($access !== null && $access !== '') {
            $query->whereExists(static function (Builder $entry) use ($siteId, $access): void {
                $entry->selectRaw('1')->from('access')
                    ->whereColumn('access.login', 'user.login')
                    ->where('access.idsite', $siteId)
                    ->where('access.access', $access);
            });
        }

        if ($search !== null && $search !== '') {
            $query->where(static function (Builder $match) use ($search): void {
                $match->where('user.login', 'like', '%'.$search.'%')
                    ->orWhere('user.email', 'like', '%'.$search.'%');
            });
        }

        $today = CarbonImmutable::now('UTC')->startOfDay()->format('Y-m-d H:i:s');
        if ($status === 'active') {
            $query->whereNull('user.invite_token')->whereNull('user.invite_expired_at');
        } elseif ($status === 'pending') {
            $query->whereNotNull('user.invite_token')->where('user.invite_expired_at', '>', $today);
            if (! $superuser) {
                $query->where('user.invited_by', $currentLogin);
            }
        } elseif ($status === 'expired') {
            $query->whereNotNull('user.invite_token')->where('user.invite_expired_at', '<', $today);
            if (! $superuser) {
                $query->where('user.invited_by', $currentLogin);
            }
        }

        if ($allowedLogins !== null) {
            $query->whereIn('user.login', $allowedLogins);
        }

        return $query;
    }
}
