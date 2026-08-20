<?php

declare(strict_types=1);

namespace App\Matomo\Referrers;

use Symfony\Component\Yaml\Yaml;

final class YamlSearchEngineDefinitionCatalog implements SearchEngineDefinitionCatalog
{
    /** @var array<string, array{name: string, backlink: string|null}>|null */
    private ?array $hosts = null;

    /** @var array<string, string>|null */
    private ?array $names = null;

    public function __construct(
        private readonly string $definitionsFile,
        private readonly string $iconsDirectory,
    ) {}

    public function url(string $name): string
    {
        $this->load();

        return isset($this->names[$name]) ? 'http://'.$this->names[$name] : 'URL unknown!';
    }

    public function logo(string $url): string
    {
        $host = $this->host($url);
        $icon = $host !== '' && is_file($this->iconsDirectory.'/'.$host.'.png') ? $host : 'xx';

        return 'plugins/Morpheus/icons/dist/searchEngines/'.$icon.'.png';
    }

    public function backlink(string $url, string $keyword): ?string
    {
        $this->load();
        $host = $this->host($url);
        $template = $this->hosts[$host]['backlink'] ?? null;

        if ($template === null || $keyword === '') {
            return null;
        }

        $keyword = str_replace('%2B', '+', urlencode($keyword));

        return rtrim($url, '/').'/'.ltrim(str_replace('{k}', $keyword, $template), '/');
    }

    private function load(): void
    {
        if ($this->hosts !== null && $this->names !== null) {
            return;
        }

        $this->hosts = [];
        $this->names = [];

        if (! is_file($this->definitionsFile)) {
            return;
        }

        $parsed = Yaml::parseFile($this->definitionsFile);

        if (! is_array($parsed)) {
            return;
        }

        foreach ($parsed as $name => $groups) {
            if (! is_string($name) || ! is_array($groups)) {
                continue;
            }

            foreach ($groups as $group) {
                if (! is_array($group) || ! is_array($group['urls'] ?? null)) {
                    continue;
                }

                $backlink = is_string($group['backlink'] ?? null) ? $group['backlink'] : null;

                foreach ($group['urls'] as $host) {
                    if (! is_string($host) || $host === '') {
                        continue;
                    }

                    $this->hosts[$host] = ['name' => $name, 'backlink' => $backlink];
                    $this->names[$name] ??= $host;
                }
            }
        }
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : trim(explode('/', str_replace('//', '', $url), 2)[0]);
    }
}
