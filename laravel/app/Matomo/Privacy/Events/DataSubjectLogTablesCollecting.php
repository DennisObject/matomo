<?php

declare(strict_types=1);

namespace App\Matomo\Privacy\Events;

use App\Matomo\Privacy\DataSubjectLogTable;

final class DataSubjectLogTablesCollecting
{
    /** @param list<DataSubjectLogTable> $tables */
    public function __construct(public array $tables) {}
}
