<?php

declare(strict_types=1);

namespace App\Matomo\Dashboard;

use App\Matomo\Authentication\ApiAuthentication;

interface DashboardRecipientPolicy
{
    public function canCopyTo(ApiAuthentication $authentication, string $login): bool;
}
