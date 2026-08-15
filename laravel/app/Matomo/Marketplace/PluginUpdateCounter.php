<?php

declare(strict_types=1);

namespace App\Matomo\Marketplace;

interface PluginUpdateCounter
{
    public function count(): int;
}
