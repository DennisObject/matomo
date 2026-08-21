<?php

declare(strict_types=1);

namespace App\Matomo\Referrers;

use Symfony\Component\Yaml\Yaml;

final class YamlSearchEngineDefinitionCatalog implements SearchEngineDefinitionCatalog
{
    /** @var array<string, array{name: string, backlink: string|null, params: list<string>}>|null */
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

    public function search(string $url): ?array
    {
        $this->load();
        $definition = $this->definition($url);
        if ($definition === null) {
            return null;
        }

        $parts = parse_url($url);
        $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
        $fragment = is_array($parts) ? ($parts['fragment'] ?? null) : null;
        if (is_string($fragment) && $fragment !== '') {
            $query .= '&'.$fragment;
        }

        $keyword = '';
        foreach ($definition['params'] as $parameter) {
            if (str_starts_with($parameter, '/')) {
                if (@preg_match($parameter, $url, $matches) === 1) {
                    $keyword = trim(urldecode($matches[1] ?? ''));
                    break;
                }

                continue;
            }

            parse_str($query, $values);
            $value = $values[$parameter] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $keyword = trim(urldecode($value));
            if ($keyword !== '') {
                break;
            }
        }

        if ($keyword === '') {
            return null;
        }

        return ['name' => $definition['name'], 'keywords' => $keyword];
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
                $params = [];
                foreach (is_array($group['params'] ?? null) ? $group['params'] : [] as $parameter) {
                    if (is_string($parameter) && $parameter !== '') {
                        $params[] = $parameter;
                    }
                }

                foreach ($group['urls'] as $host) {
                    if (! is_string($host) || $host === '') {
                        continue;
                    }

                    $this->hosts[strtolower($host)] = ['name' => $name, 'backlink' => $backlink, 'params' => $params];
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

    /** @return array{name: string, backlink: string|null, params: list<string>}|null */
    private function definition(string $url): ?array
    {
        $host = strtolower($this->host($url));
        if ($host === '') {
            return null;
        }

        $this->hosts ??= [];
        if (isset($this->hosts[$host])) {
            return $this->hosts[$host];
        }

        $withoutWww = preg_replace('/^www\./', '', $host) ?? $host;
        if (isset($this->hosts[$withoutWww])) {
            return $this->hosts[$withoutWww];
        }

        foreach ($this->hosts as $pattern => $definition) {
            if (! str_contains($pattern, '{}')) {
                continue;
            }

            $regex = '#^'.str_replace('\{\}', '[^.]+', preg_quote($pattern, '#')).'$#i';
            if (preg_match($regex, $host) === 1 || preg_match($regex, $withoutWww) === 1) {
                return $definition;
            }
        }

        return null;
    }
}
