<?php

declare(strict_types=1);

namespace App\Matomo\Sites\Events;

final class ImageTrackingCodeGenerating
{
    /** @param array<string, float|int|string> $parameters */
    public function __construct(public string $host, public array $parameters) {}
}
