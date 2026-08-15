<?php

declare(strict_types=1);

namespace App\Matomo\Marketplace;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Security\EgressHostResolver;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;

final readonly class HttpPluginUpdateCounter implements PluginUpdateCounter
{
    public function __construct(
        private Factory $http,
        private Repository $cache,
        private InstallationConfig $installation,
        private EgressHostResolver $hosts,
        private string $endpoint,
        private string $pluginsPath,
        private bool $enabled,
    ) {}

    public function count(): int
    {
        if (! $this->enabled) {
            return 0;
        }

        return (int) $this->cache->remember(
            'CorePluginsAdmin_NumberOfPluginUpdates',
            300,
            fn (): int => $this->fetch(),
        );
    }

    private function fetch(): int
    {
        $plugins = [];
        foreach ($this->installation->activatedPlugins() as $pluginName) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $pluginName) !== 1) {
                continue;
            }

            $manifest = $this->pluginsPath.'/'.$pluginName.'/plugin.json';
            if (! is_file($manifest)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($manifest), true);
            $version = is_array($decoded) ? ($decoded['version'] ?? null) : null;
            if (is_string($version) && $version !== '') {
                $plugins[] = ['name' => $pluginName, 'version' => $version, 'activated' => 1];
            }
        }

        if ($plugins === []) {
            return 0;
        }

        $url = rtrim($this->endpoint, '/').'/plugins/checkUpdates';
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new MarketplaceException('The Marketplace endpoint is invalid.', 500);
        }

        [$canonicalHost, $address] = $this->hosts->resolveTarget($host);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $curl = [CURLOPT_PROXY => ''];
        if ($canonicalHost !== $address) {
            $address = str_contains($address, ':') ? "[{$address}]" : $address;
            $curl[CURLOPT_RESOLVE] = ["{$canonicalHost}:{$port}:{$address}"];
        }

        $response = $this->http->timeout(60)->connectTimeout(5)->withOptions([
            'allow_redirects' => false, 'proxy' => '', 'curl' => $curl,
        ])->asForm()->post($url, ['plugins' => json_encode(['plugins' => $plugins], JSON_THROW_ON_ERROR)]);
        if (! $response->successful()) {
            return 0;
        }

        $updates = $response->json();

        return is_array($updates) ? count(array_filter($updates, is_array(...))) : 0;
    }
}
