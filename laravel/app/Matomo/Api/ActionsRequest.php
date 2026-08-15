<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ActionsRequest
{
    public function __construct(
        public ?string $actionValue,
        public ?int $depth,
    ) {}
}
