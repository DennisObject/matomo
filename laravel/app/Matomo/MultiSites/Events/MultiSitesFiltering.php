<?php

declare(strict_types=1);

namespace App\Matomo\MultiSites\Events;

final class MultiSitesFiltering
{
    /**
     * @param  list<int>  $siteIds
     */
    public function __construct(public array $siteIds) {}
}
