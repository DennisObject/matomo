<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface AnonymizableColumnProvider
{
    /** @return list<array{column_name: string, default_value: mixed}> */
    public function forTable(string $table): array;
}
