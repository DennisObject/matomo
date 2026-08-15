<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class SegmentEditorRequest
{
    public function __construct(
        public ?int $segmentId,
        public ?int $siteId,
        public ?string $name,
        public ?string $definition,
        public ?bool $autoArchive,
        public ?bool $enabledAllUsers,
        public ?string $period,
        public ?string $date,
        public ?string $segment,
    ) {}
}
