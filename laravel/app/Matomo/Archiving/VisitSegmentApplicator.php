<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use Illuminate\Database\Query\Builder;

interface VisitSegmentApplicator
{
    public function apply(Builder $query, ?string $segment, ?int $siteId = null): bool;
}
