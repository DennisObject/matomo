<?php

declare(strict_types=1);

namespace App\Support;

final class MatomoProductUrl
{
    private const string HOST = 'matomo.org';

    public static function https(string $path): string
    {
        return 'https://'.self::HOST.'/'.ltrim($path, '/');
    }
}
