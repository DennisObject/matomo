<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

interface SegmentSuggestionPolicy
{
    public function enabled(): bool;
}
