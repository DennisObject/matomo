<?php

declare(strict_types=1);

namespace App\Matomo\MultiSites\Events;

final class MultiSitesTotalsFiltering
{
    /**
     * @param  list<array<string, float|int|string|null>>  $rows
     */
    public function __construct(public array $rows) {}
}
