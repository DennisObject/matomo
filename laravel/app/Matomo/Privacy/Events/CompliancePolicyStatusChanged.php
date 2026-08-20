<?php

declare(strict_types=1);

namespace App\Matomo\Privacy\Events;

final readonly class CompliancePolicyStatusChanged
{
    public function __construct(
        public bool $active,
        public ?int $idSite,
        public string $policy,
    ) {}
}
