<?php

declare(strict_types=1);

namespace App\Matomo\Api;

use App\Matomo\Authentication\ApiAuthentication;

interface BulkRequestLimit
{
    public function current(ApiAuthentication $authentication): int;
}
