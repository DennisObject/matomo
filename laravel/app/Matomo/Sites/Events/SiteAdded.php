<?php

declare(strict_types=1);

namespace App\Matomo\Sites\Events;

final readonly class SiteAdded
{
    public function __construct(public int $siteId) {}
}
