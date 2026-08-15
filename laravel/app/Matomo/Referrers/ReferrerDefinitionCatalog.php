<?php

declare(strict_types=1);

namespace App\Matomo\Referrers;

interface ReferrerDefinitionCatalog
{
    public function socialName(string $url): ?string;

    public function aiAssistantName(string $url): ?string;
}
