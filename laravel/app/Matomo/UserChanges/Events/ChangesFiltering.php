<?php

declare(strict_types=1);

namespace App\Matomo\UserChanges\Events;

final class ChangesFiltering
{
    /** @param array<int, array<string, mixed>> $changes */
    public function __construct(public array $changes) {}
}
