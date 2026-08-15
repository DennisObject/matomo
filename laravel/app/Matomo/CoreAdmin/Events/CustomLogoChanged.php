<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin\Events;

final readonly class CustomLogoChanged
{
    public function __construct(public string $absolutePath) {}
}
