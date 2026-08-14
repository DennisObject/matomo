<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface SegmentHashResolver
{
    public function resolve(?string $segment): string;
}
