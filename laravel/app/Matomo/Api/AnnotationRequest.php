<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class AnnotationRequest
{
    /** @param list<int> $siteIds */
    public function __construct(
        public array $siteIds,
        public bool $allSites,
        public ?int $noteId,
        public ?string $date,
        public ?string $note,
        public ?bool $starred,
        public string $period,
        public ?int $lastN,
        public bool $includeText,
    ) {}
}
