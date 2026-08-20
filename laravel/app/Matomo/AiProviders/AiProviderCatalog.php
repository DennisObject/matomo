<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

interface AiProviderCatalog
{
    /** @return list<AiProviderDefinition> */
    public function all(): array;

    /** @return list<AiProviderDefinition> */
    public function selectable(): array;

    public function find(string $providerId): ?AiProviderDefinition;

    public function isSelectable(string $providerId): bool;
}
