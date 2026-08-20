<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface SiteSettingsProvider
{
    /** @return list<array<string, mixed>> */
    public function metadata(int $siteId, string $language): array;
}
