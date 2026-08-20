<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

interface TrustedHostConfiguration
{
    /** @param list<string> $hosts */
    public function replace(array $hosts): void;
}
