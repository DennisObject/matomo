<?php

declare(strict_types=1);

namespace App\Matomo\TrackingFailures\Events;

final class TrackingFailuresMakingHumanReadable
{
    /** @param list<array<string, mixed>> $failures */
    public function __construct(public array $failures) {}
}
