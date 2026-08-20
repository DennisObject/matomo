<?php

declare(strict_types=1);

namespace App\Matomo\Overlay;

use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\QueryParameterExclusionPolicy;
use App\Matomo\Sites\SiteRepository;

final readonly class PageUrlQueryParameterFilter
{
    public function __construct(
        private SiteRepository $sites,
        private QueryParameterExclusionPolicy $globalExclusions,
        private OptionRepository $options,
        private OverlaySettings $settings,
    ) {}

    public function filter(string $url, int $siteId): ?string
    {
        $url = substr(
            str_replace(["\n", "\r", "\0"], '', trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5))),
            0,
            $this->settings->pageMaximumLength(),
        );
        $url = $this->convertMatrixUrl($url);

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        if (isset($parts['host'])) {
            $parts['host'] = mb_strtolower($parts['host']);
        }

        if (! $this->keepsFragment($siteId)) {
            unset($parts['fragment']);
        }

        $excludedParameters = $this->excludedParameters($siteId);

        if (isset($parts['query']) && $parts['query'] !== '') {
            $parts['query'] = $this->filterQuery($parts['query'], $excludedParameters);
        } elseif (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $parts['fragment'] = $this->filterQuery($parts['fragment'], $excludedParameters);
        }

        return $this->rebuild($parts);
    }

    private function convertMatrixUrl(string $url): string
    {
        $semicolon = strpos($url, ';');

        if ($semicolon === false) {
            return $url;
        }

        $questionMark = strpos($url, '?');

        if ($questionMark === false || $questionMark > $semicolon) {
            if ($questionMark !== false) {
                $url = substr_replace($url, ';', $questionMark, 1);
            }

            $url = substr_replace($url, '?', $semicolon, 1);

            return str_replace(';', '&', $url);
        }

        return $url;
    }

    private function keepsFragment(int $siteId): bool
    {
        $value = $this->sites->details($siteId)['keep_url_fragment'] ?? null;

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        return $this->options->value('SitesManager_KeepURLFragmentsGlobal') === '1';
    }

    /** @return list<string> */
    private function excludedParameters(int $siteId): array
    {
        $configured = [
            'ignore_referrer',
            'ignore_referer',
            ...$this->settings->urlQueryParametersToExclude(),
            ...$this->settings->campaignNameParameters(),
            ...$this->settings->campaignKeywordParameters(),
        ];
        $siteParameters = $this->sites->excludedParameters($siteId) ?? '';
        $globalParameters = $this->globalExclusions->parameters($siteId);

        foreach (explode(',', $siteParameters.','.$globalParameters) as $parameter) {
            $configured[] = $parameter;
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (string $parameter): string => mb_strtolower(trim($parameter)),
                $configured,
            ),
            static fn (string $parameter): bool => $parameter !== '',
        )));
    }

    /**
     * @param  list<string>  $excludedParameters
     */
    private function filterQuery(string $query, array $excludedParameters): string
    {
        /** @var array<string, string|false|list<string|false>> $parameters */
        $parameters = [];

        foreach (explode('&', ltrim($query, '?')) as $parameter) {
            if ($parameter === '') {
                continue;
            }

            $separator = strpos($parameter, '=');
            $name = $separator === false ? $parameter : substr($parameter, 0, $separator);
            $value = $separator === false ? false : substr($parameter, $separator + 1);

            if ($name === '') {
                continue;
            }

            $arrayName = preg_replace('/(\[|%5b)(]|%5d)$/i', '', $name, -1, $arraySuffixes);

            if ($arraySuffixes > 0 && is_string($arrayName) && $arrayName !== '') {
                if (! isset($parameters[$arrayName]) || ! is_array($parameters[$arrayName])) {
                    $parameters[$arrayName] = [];
                }

                $parameters[$arrayName][] = $value;

                continue;
            }

            $parameters[$name] = $value;
        }

        $kept = [];

        foreach ($parameters as $name => $value) {
            $decodedName = str_ireplace(['%5b', '%5d'], ['[', ']'], $name);

            if ($this->isExcluded(mb_strtolower($decodedName), $excludedParameters)) {
                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                $suffix = is_array($value) ? '[]' : '';
                $kept[] = $decodedName.$suffix.($item === false ? '' : '='.$item);
            }
        }

        return implode('&', $kept);
    }

    /** @param list<string> $excludedParameters */
    private function isExcluded(string $name, array $excludedParameters): bool
    {
        foreach ($excludedParameters as $excludedParameter) {
            if (@preg_match($excludedParameter, '') === false) {
                if ($name === $excludedParameter) {
                    return true;
                }

                continue;
            }

            if (preg_match($excludedParameter, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     scheme?: string,
     *     host?: string,
     *     port?: int,
     *     user?: string,
     *     pass?: string,
     *     path?: string,
     *     query?: string,
     *     fragment?: string
     * } $parts
     */
    private function rebuild(array $parts): string
    {
        $scheme = isset($parts['scheme'])
            ? $parts['scheme'].(strcasecmp($parts['scheme'], 'mailto') === 0 ? ':' : '://')
            : '';
        $credentials = '';

        if (isset($parts['user']) && $parts['user'] !== '') {
            $credentials = $this->encodeCredential($parts['user']);

            if (isset($parts['pass']) && $parts['pass'] !== '') {
                $credentials .= ':'.$this->encodeCredential($parts['pass']);
            }

            $credentials .= '@';
        }

        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';

        if ($path !== '' && ! str_starts_with($path, '/') && $scheme.$credentials.$host.$port !== '') {
            $path = '/'.$path;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragmentValue = $parts['fragment'] ?? '';

        if (str_ends_with($fragmentValue, '#')) {
            $fragmentValue = substr($fragmentValue, 0, -1);
        }

        $fragment = $fragmentValue !== '' ? '#'.$fragmentValue : '';

        return $scheme.$credentials.$host.$port.$path.$query.$fragment;
    }

    private function encodeCredential(string $value): string
    {
        return str_replace([':', '@', '/', '\\'], ['%3A', '%40', '%2F', '%5C'], $value);
    }
}
