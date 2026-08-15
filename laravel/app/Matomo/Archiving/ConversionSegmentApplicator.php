<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use Illuminate\Database\Query\Builder;

interface ConversionSegmentApplicator
{
    public function applyToConversions(
        Builder $query,
        ?string $segment,
        ?int $siteId = null,
    ): bool;
}
