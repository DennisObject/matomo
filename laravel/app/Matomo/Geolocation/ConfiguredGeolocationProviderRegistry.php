<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use App\Matomo\Options\MutableOptionRepository;
use InvalidArgumentException;

final readonly class ConfiguredGeolocationProviderRegistry implements GeolocationProviderRegistry
{
    private const string CURRENT_PROVIDER_OPTION = 'usercountry.location_provider';

    /** @param list<GeolocationProvider> $providers */
    public function __construct(
        private array $providers,
        private MutableOptionRepository $options,
        private TrackerCacheInvalidator $trackerCache,
        private string $defaultProviderId,
    ) {}

    public function locate(
        string $ipAddress,
        string $browserLanguage,
        string $currentIpAddress,
        ?string $providerId = null,
    ): ?array {
        $providerId ??= $this->options->value(self::CURRENT_PROVIDER_OPTION) ?? $this->defaultProviderId;
        $provider = $this->provider($providerId);

        if ($provider === null) {
            throw new InvalidArgumentException(
                "Cannot find the '{$providerId}' provider. It is either an invalid provider ID or the ID of a provider that is not working.",
            );
        }

        return $provider->locate($ipAddress, $browserLanguage, $currentIpAddress);
    }

    public function setCurrent(string $providerId): void
    {
        $provider = $this->provider($providerId);

        if ($provider === null) {
            throw new InvalidArgumentException(
                "Invalid provider ID '{$providerId}'. The provider either does not exist or is not available",
            );
        }

        $provider->activate();
        $this->options->set(self::CURRENT_PROVIDER_OPTION, $providerId);
        $this->trackerCache->clearGeneral();
    }

    private function provider(string $providerId): ?GeolocationProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->id() === $providerId && $provider->available()) {
                return $provider;
            }
        }

        return null;
    }
}
