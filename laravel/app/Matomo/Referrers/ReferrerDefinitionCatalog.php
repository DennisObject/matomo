<?php

declare(strict_types=1);

namespace App\Matomo\Referrers;

interface ReferrerDefinitionCatalog
{
    public function socialName(string $url): ?string;

    public function aiAssistantName(string $url): ?string;

    public function socialUrl(string $name): ?string;

    public function aiAssistantUrl(string $name): ?string;

    public function socialNameAtPosition(int $position): ?string;

    public function socialLogo(string $name): string;

    public function aiAssistantLogo(string $name): string;
}
