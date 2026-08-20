<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders\Events;

use App\Matomo\AiProviders\AiProviderDefinition;

final class AiProvidersCollecting
{
    /** @var array<string, AiProviderDefinition> */
    private array $providers = [];

    /** @var array<string, bool> */
    private array $selectable = [];

    /** @param list<AiProviderDefinition> $providers */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->add($provider);
        }
    }

    public function add(AiProviderDefinition $provider, bool $selectable = true): bool
    {
        if (isset($this->providers[$provider->id])) {
            return false;
        }

        $this->providers[$provider->id] = $provider;
        $this->selectable[$provider->id] = $selectable;

        return true;
    }

    public function remove(string $providerId): void
    {
        unset($this->providers[$providerId], $this->selectable[$providerId]);
    }

    public function setSelectable(string $providerId, bool $selectable): void
    {
        if (isset($this->providers[$providerId])) {
            $this->selectable[$providerId] = $selectable;
        }
    }

    /** @return list<AiProviderDefinition> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** @return list<AiProviderDefinition> */
    public function selectable(): array
    {
        return array_values(array_filter(
            $this->providers,
            fn (AiProviderDefinition $provider): bool => $this->isSelectable($provider->id),
        ));
    }

    public function isSelectable(string $providerId): bool
    {
        return $this->selectable[$providerId] ?? false;
    }
}
