<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

interface ServerVariableMapping
{
    /** @return array<string, string> */
    public function variables(): array;
}
