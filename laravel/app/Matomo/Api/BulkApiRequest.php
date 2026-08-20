<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class BulkApiRequest
{
    /** @param list<string> $urls */
    public function __construct(public array $urls) {}
}
