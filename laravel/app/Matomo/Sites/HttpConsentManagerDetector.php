<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use App\Matomo\Security\BlockedEgressTarget;
use App\Matomo\Security\EgressHostResolver;
use Exception;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

final readonly class HttpConsentManagerDetector implements ConsentManagerDetector
{
    private const int CACHE_SECONDS = 604_800;

    private const int MAX_REDIRECTS = 5;

    /**
     * @var list<array{
     *     name: string,
     *     url: string,
     *     detected: non-empty-list<string>,
     *     connected: non-empty-list<string>
     * }>
     */
    private const array DEFINITIONS = [
        [
            'name' => 'Complianz',
            'url' => 'https://matomo.org/faq/how-to/using-complianz-for-wordpress-consent-manager-with-matomo',
            'detected' => ['complianz-gdpr'],
            'connected' => ["if (!cmplz_in_array( 'statistics', consentedCategories )) {\n\t\t_paq.push(['forgetCookieConsentGiven']);"],
        ],
        [
            'name' => 'CookieYes',
            'url' => 'https://matomo.org/faq/how-to/using-cookieyes-consent-manager-with-matomo',
            'detected' => ['cookieyes.com'],
            'connected' => ['document.addEventListener("cookieyes_consent_update", function (eventData)'],
        ],
        [
            'name' => 'Cookiebot',
            'url' => 'https://matomo.org/faq/how-to/using-cookiebot-consent-manager-with-matomo',
            'detected' => ['cookiebot.com'],
            'connected' => ["typeof _paq === 'undefined' || typeof Cookiebot === 'undefined'"],
        ],
        [
            'name' => 'Klaro',
            'url' => 'https://matomo.org/faq/how-to/using-klaro-consent-manager-with-matomo',
            'detected' => ['klaro.js', 'kiprotect.com'],
            'connected' => ['KlaroWatcher()', "title: 'Matomo',"],
        ],
        [
            'name' => 'Osano',
            'url' => 'https://matomo.org/faq/how-to/using-osano-consent-manager-with-matomo',
            'detected' => ['osano.com'],
            'connected' => ["Osano.cm.addEventListener('osano-cm-consent-changed', (change) => { console.log('cm-change'); consentSet(change); });"],
        ],
        [
            'name' => 'Tarte au Citron',
            'url' => 'https://matomo.org/faq/how-to/using-tarte-au-citron-consent-manager-with-matomo',
            'detected' => ['tarteaucitron.js'],
            'connected' => ['tarteaucitron.user.matomoHost'],
        ],
    ];

    public function __construct(
        private Factory $http,
        private CacheRepository $cache,
        private LoggerInterface $logger,
        private EgressHostResolver $hosts,
        private bool $internetFeaturesEnabled,
        private ?string $outboundProxyHost = null,
        /** @var list<string> */
        private array $outboundProxyExcludedHosts = [],
    ) {}

    public function detect(string $url, int $timeout): ?array
    {
        $cacheKey = 'Laravel_SiteConsentManager_'.md5($url);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached) && array_key_exists('result', $cached)) {
            $result = $cached['result'];

            if ($result === null || $this->isDetectionResult($result)) {
                return $result;
            }
        }

        if (! $this->internetFeaturesEnabled) {
            return null;
        }

        try {
            $content = $this->fetch($url, $timeout);
        } catch (BlockedEgressTarget $exception) {
            $this->logger->warning('Site consent-manager request for {host} was refused: {message}', [
                'host' => $this->urlHost($url),
                'message' => $exception->getMessage(),
            ]);

            return null;
        } catch (Exception $exception) {
            $this->logger->debug('Site consent-manager request for {host} failed ({exception}).', [
                'host' => $this->urlHost($url),
                'exception' => $exception::class,
            ]);

            return null;
        }

        if ($content === null || $content === '') {
            return null;
        }

        $result = $this->detectInContent($content);
        $this->cache->put($cacheKey, ['result' => $result], self::CACHE_SECONDS);

        return $result;
    }

    private function fetch(string $url, int $timeout): ?string
    {
        if (! function_exists('curl_init')) {
            throw new BlockedEgressTarget('SSRF-safe HTTP requests require the curl PHP extension.');
        }

        $startedAt = microtime(true);
        $uri = new Uri($url);

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $elapsed = microtime(true) - $startedAt;

            if ($elapsed >= $timeout) {
                return null;
            }

            $response = $this->request($uri, max(1, (int) floor($timeout - $elapsed)));

            if ($response->redirect() && $response->status() !== 304) {
                $location = $response->header('Location');

                if ($location === '' || $redirects === self::MAX_REDIRECTS) {
                    return null;
                }

                $uri = UriResolver::resolve($uri, new Uri($location));

                continue;
            }

            return $response->successful() ? $response->body() : null;
        }

        return null;
    }

    private function request(UriInterface $uri, int $timeout): Response
    {
        $scheme = strtolower($uri->getScheme());

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new BlockedEgressTarget(
                'SSRF-safe HTTP requests only support the http and https schemes.',
            );
        }

        if ($this->outboundProxyAppliesTo($uri->getHost())) {
            throw new BlockedEgressTarget(
                'SSRF-safe HTTP requests cannot be routed through a configured proxy.',
            );
        }

        [$canonicalHost, $pinnedIp] = $this->hosts->resolveTarget($uri->getHost());
        $uri = $uri->withHost($canonicalHost);
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $curlOptions = [CURLOPT_PROXY => ''];

        if ($canonicalHost !== $pinnedIp) {
            $pinnedAddress = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
            $curlOptions[CURLOPT_RESOLVE] = ["{$canonicalHost}:{$port}:{$pinnedAddress}"];
        }

        return $this->http
            ->timeout($timeout)
            ->connectTimeout(min(3, $timeout))
            ->withoutVerifying()
            ->withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => $curlOptions,
            ])
            ->get((string) $uri);
    }

    private function outboundProxyAppliesTo(string $host): bool
    {
        if ($this->outboundProxyHost === null) {
            return false;
        }

        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        return ! in_array($host, $this->outboundProxyExcludedHosts, true);
    }

    /**
     * @return array{name: string, url: string|null, isConnected: bool}|null
     */
    private function detectInContent(string $content): ?array
    {
        foreach (self::DEFINITIONS as $definition) {
            if (! $this->containsAny($content, $definition['detected'])) {
                continue;
            }

            return [
                'name' => $definition['name'],
                'url' => $definition['url'],
                'isConnected' => $this->containsAny($content, $definition['connected']),
            ];
        }

        return null;
    }

    /**
     * @param  non-empty-list<string>  $needles
     */
    private function containsAny(string $content, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function urlHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private function isDetectionResult(mixed $result): bool
    {
        return is_array($result)
            && is_string($result['name'] ?? null)
            && array_key_exists('url', $result)
            && (is_string($result['url']) || $result['url'] === null)
            && is_bool($result['isConnected'] ?? null);
    }
}
