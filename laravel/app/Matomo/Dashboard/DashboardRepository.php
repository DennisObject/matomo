<?php

declare(strict_types=1);

namespace App\Matomo\Dashboard;

interface DashboardRepository
{
    /** @return list<array{iddashboard: int, name: string|null, layout: string}> */
    public function all(string $login): array;

    public function layout(string $login, int $dashboardId): ?string;

    public function create(string $login, string $name, string $layout): int;

    public function delete(string $login, int $dashboardId): void;

    public function updateLayout(string $login, int $dashboardId, string $layout): void;
}
