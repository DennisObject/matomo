<?php

declare(strict_types=1);

namespace App\Matomo\Options;

interface OptionRepository
{
    public function value(string $name): ?string;
}
