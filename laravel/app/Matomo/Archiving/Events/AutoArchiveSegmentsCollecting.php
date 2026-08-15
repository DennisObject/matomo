<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

final class AutoArchiveSegmentsCollecting
{
    /** @param list<string> $definitions */
    public function __construct(
        public array $definitions,
        public readonly ?int $siteId,
    ) {}
}
