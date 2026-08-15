<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class CustomDimensionsRequest
{
    public function __construct(
        public ?int $siteId,
        public ?int $dimensionId,
        public ?string $scope,
    ) {}
}
