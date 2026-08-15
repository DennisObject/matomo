<?php

declare(strict_types=1);

namespace App\Matomo\Sites\Events;

final readonly class SiteDeleted
{
    public function __construct(public int $siteId) {}
}
