<?php

declare(strict_types=1);

namespace App\Matomo\Dashboard;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseDashboardRepository implements DashboardRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function all(string $login): array
    {
        return array_values($this->connection
            ->table('user_dashboard')
            ->select(['iddashboard', 'name', 'layout'])
            ->where('login', $login)
            ->orderBy('iddashboard')
            ->get()
            ->map(static fn (object $row): array => [
                'iddashboard' => (int) (get_object_vars($row)['iddashboard'] ?? 0),
                'name' => is_string(get_object_vars($row)['name'] ?? null)
                    ? get_object_vars($row)['name']
                    : null,
                'layout' => is_string(get_object_vars($row)['layout'] ?? null)
                    ? get_object_vars($row)['layout']
                    : '',
            ])
            ->all());
    }

    public function layout(string $login, int $dashboardId): ?string
    {
        $layout = $this->connection
            ->table('user_dashboard')
            ->where('login', $login)
            ->where('iddashboard', $dashboardId)
            ->value('layout');

        return is_string($layout) ? $layout : null;
    }

    public function create(string $login, string $name, string $layout): int
    {
        return $this->connection->transaction(function () use ($login, $name, $layout): int {
            $maximumId = $this->connection
                ->table('user_dashboard')
                ->where('login', $login)
                ->lockForUpdate()
                ->max('iddashboard');
            $dashboardId = is_numeric($maximumId) ? (int) $maximumId + 1 : 1;

            $this->connection->table('user_dashboard')->insert([
                'login' => $login,
                'iddashboard' => $dashboardId,
                'name' => $name,
                'layout' => $layout,
            ]);

            return $dashboardId;
        });
    }

    public function delete(string $login, int $dashboardId): void
    {
        $this->connection
            ->table('user_dashboard')
            ->where('login', $login)
            ->where('iddashboard', $dashboardId)
            ->delete();
    }

    public function updateLayout(string $login, int $dashboardId, string $layout): void
    {
        $this->connection->table('user_dashboard')->updateOrInsert(
            ['login' => $login, 'iddashboard' => $dashboardId],
            ['layout' => $layout],
        );
    }
}
