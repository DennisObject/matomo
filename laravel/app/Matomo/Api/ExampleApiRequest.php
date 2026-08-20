<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ExampleApiRequest
{
    public function __construct(
        public float $a,
        public float $b,
    ) {}
}
