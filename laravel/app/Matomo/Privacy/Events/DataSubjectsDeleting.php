<?php

declare(strict_types=1);

namespace App\Matomo\Privacy\Events;

final class DataSubjectsDeleting
{
    /**
     * @param  list<array{idsite: int, idvisit: int}>  $visits
     * @param  array<string, int>  $deleted
     */
    public function __construct(public readonly array $visits, public array $deleted) {}
}
