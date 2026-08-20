<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ExampleUiRequest
{
    public function __construct(
        public ?string $date,
        public ?string $period,
    ) {}
}
