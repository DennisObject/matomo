<?php

declare(strict_types=1);

namespace App\Matomo\Dashboard\Events;

final class DashboardDefaultLayoutChanging
{
    public function __construct(public string $layout) {}
}
