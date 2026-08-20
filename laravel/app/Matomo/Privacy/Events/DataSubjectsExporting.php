<?php

declare(strict_types=1);

namespace App\Matomo\Privacy\Events;

final class DataSubjectsExporting
{
    /**
     * @param  list<array{idsite: int, idvisit: int}>  $visits
     * @param  array<string, list<array<string, mixed>>>  $data
     */
    public function __construct(public readonly array $visits, public array $data) {}
}
