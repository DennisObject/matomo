<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class LanguagesManagerRequest
{
    public function __construct(
        public string $languageCode,
        public string $login,
        public bool $ignoreConfig,
        public bool $excludeNonCorePlugins,
        public bool $use12HourClock,
    ) {}
}
