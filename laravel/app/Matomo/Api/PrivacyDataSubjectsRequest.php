<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyDataSubjectsRequest
{
    /** @param list<array{idsite: int, idvisit: int}> $visits */
    public function __construct(
        public array $visits,
        public bool $delete,
    ) {}
}
