<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Authentication\ApiAuthentication;

interface SegmentCreationAuthorizer
{
    public function allowed(ApiAuthentication $authentication, ?int $siteId): bool;
}
