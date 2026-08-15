<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ActionArchivePathResolver
{
    public const int PAGE_URL = 1;

    public const int OUTLINK = 2;

    public const int DOWNLOAD = 3;

    public const int PAGE_TITLE = 4;

    public const int SITE_SEARCH = 8;

    private const string URL_LEAF_MARKER = '/';

    private const string TITLE_LEAF_MARKER = ' ';

    public function __construct(private ActionArchiveConfiguration $configuration) {}

    /** @return non-empty-list<string> */
    public function path(string $name, int $type, ?int $urlPrefix): array
    {
        if ($type === self::SITE_SEARCH) {
            return [$name];
        }

        $name = str_replace("\n", '', $name);

        if ($type === self::PAGE_TITLE && $this->configuration->titleDelimiter === '') {
            $label = trim($name);

            return [self::TITLE_LEAF_MARKER.($label === '' ? 'Page Name not defined' : $label)];
        }

        $parsed = $this->parseUrl($name, $type, $urlPrefix);

        if (is_array($parsed)) {
            return $parsed;
        }

        $delimiter = $type === self::PAGE_TITLE
            ? $this->configuration->titleDelimiter
            : $this->configuration->urlDelimiter;
        $segments = $delimiter === ''
            ? [trim($parsed)]
            : array_values(array_filter(
                array_map(trim(...), explode(
                    $delimiter,
                    $parsed,
                    $this->configuration->categoryLevelLimit,
                )),
                static fn (string $segment): bool => $segment !== '',
            ));

        if ($segments === []) {
            return [$type === self::PAGE_TITLE
                ? 'Page Name not defined'
                : 'Page URL not defined'];
        }

        $last = array_key_last($segments);
        $segments[$last] = ($type === self::PAGE_TITLE
            ? self::TITLE_LEAF_MARKER
            : self::URL_LEAF_MARKER).$segments[$last];

        return $segments;
    }

    /** @param non-empty-list<string> $path */
    public function flatLabel(array $path, int $type): string
    {
        $segments = $path;
        $last = array_key_last($segments);
        $segments[$last] = ltrim(
            $segments[$last],
            $type === self::PAGE_TITLE ? self::TITLE_LEAF_MARKER : self::URL_LEAF_MARKER,
        );
        $delimiter = $type === self::PAGE_TITLE
            ? $this->configuration->titleDelimiter
            : $this->configuration->urlDelimiter;
        $label = implode($delimiter, $segments);

        return $type === self::PAGE_URL
            && $label !== 'Page URL not defined'
            && ! str_starts_with($label, '/')
                ? '/'.$label
                : $label;
    }

    public function reconstructedUrl(string $name, ?int $prefix): string
    {
        $prefixes = [
            0 => 'http://',
            1 => 'http://www.',
            2 => 'https://',
            3 => 'https://www.',
        ];

        return ($prefix === null ? '' : ($prefixes[$prefix] ?? '')).$name;
    }

    /** @return string|non-empty-list<string> */
    private function parseUrl(string $name, int $type, ?int $urlPrefix): string|array
    {
        $pattern = $urlPrefix === null
            ? '@^https?://([^/]+)[/]?([^#]*)[#]?(.*)$@i'
            : '@^([^/]+)[/]?([^#]*)[#]?(.*)$@i';

        if (preg_match($pattern, $name, $matches) !== 1) {
            return $name;
        }

        $host = trim($matches[1]);
        $path = $matches[2];
        $fragment = rtrim($matches[3], '#');

        if ($type === self::DOWNLOAD || $type === self::OUTLINK) {
            return [$host, '/'.trim($path).($fragment === '' ? '' : '#'.$fragment)];
        }

        if ($path === '' || str_ends_with($path, '/')) {
            $path .= $this->configuration->defaultActionName;
        }

        return $path.($fragment === '' ? '' : '#'.$fragment);
    }
}
