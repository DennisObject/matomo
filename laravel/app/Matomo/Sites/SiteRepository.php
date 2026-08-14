<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface SiteRepository
{
    /**
     * @return list<int>
     */
    public function allIds(): array;
}
