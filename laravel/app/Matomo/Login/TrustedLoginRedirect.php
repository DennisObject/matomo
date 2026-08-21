<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use Illuminate\Http\Request;

final readonly class TrustedLoginRedirect
{
    private const string HOME = '/index.php?module=CoreHome&action=index';

    /**
     * @param  list<string>  $trustedHosts
     */
    public function __construct(
        private array $trustedHosts = [],
        private bool $trustedHostCheckEnabled = true,
    ) {}

    public function destination(Request $request, string $parameter = 'form_redirect'): string
    {
        $redirect = (string) $request->input($parameter, '');
        if ($redirect === '') {
            return self::HOME;
        }

        $parsed = parse_url($redirect);
        if ($parsed === false || (isset($parsed['scheme']) && ! isset($parsed['host']))) {
            return self::HOME;
        }

        parse_str($parsed['query'] ?? '', $parameters);
        $module = is_string($parameters['module'] ?? null) ? $parameters['module'] : '';
        if ($module === '' || strcasecmp($module, 'Login') === 0) {
            return self::HOME;
        }

        $host = $parsed['host'] ?? '';
        $currentHost = explode(':', $request->getHost(), 2)[0];
        if ($host === '' || strcasecmp($host, $currentHost) !== 0 || ! $this->isTrusted($host)) {
            return self::HOME;
        }

        $path = $parsed['path'] ?? '/index.php';
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $query = $parsed['query'] ?? '';

        return $query === '' ? $path : $path.'?'.$query;
    }

    private function isTrusted(string $host): bool
    {
        if (! $this->trustedHostCheckEnabled || $this->trustedHosts === []) {
            return true;
        }

        $host = rtrim(strtolower($host), '.');
        foreach ($this->trustedHosts as $trustedHost) {
            $trustedHost = rtrim(strtolower($trustedHost), '.');
            if ($host === $trustedHost || str_ends_with($host, '.'.$trustedHost)) {
                return true;
            }
        }

        return false;
    }
}
