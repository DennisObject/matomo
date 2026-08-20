<?php

declare(strict_types=1);

namespace App\Matomo\Dashboard;

use App\Matomo\Authentication\ApiAuthentication;

interface DashboardLayoutProvider
{
    public function defaultLayout(ApiAuthentication $authentication): string;

    /** @return list<array{module: string, action: string}> */
    public function visibleWidgets(string $layout): array;
}
