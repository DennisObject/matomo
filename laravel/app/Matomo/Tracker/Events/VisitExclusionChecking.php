<?php

declare(strict_types=1);

namespace App\Matomo\Tracker\Events;

use Illuminate\Http\Request;

final class VisitExclusionChecking
{
    public function __construct(
        public readonly Request $request,
        public bool $excluded = false,
    ) {}
}
