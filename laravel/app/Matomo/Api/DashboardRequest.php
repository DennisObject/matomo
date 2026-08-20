<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class DashboardRequest
{
    public function __construct(
        public string $login = '',
        public string $dashboardName = '',
        public string $copyToUser = '',
        public ?int $dashboardId = null,
        public bool $returnDefaultIfEmpty = true,
        public bool $addDefaultWidgets = true,
    ) {}
}
