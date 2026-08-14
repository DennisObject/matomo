<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface CurrencyProvider
{
    /**
     * @return array<string, string>
     */
    public function symbols(): array;
}
