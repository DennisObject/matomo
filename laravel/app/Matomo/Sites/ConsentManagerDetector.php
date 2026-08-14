<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface ConsentManagerDetector
{
    /**
     * @return array{name: string, url: string|null, isConnected: bool}|null
     */
    public function detect(string $url, int $timeout): ?array;
}
