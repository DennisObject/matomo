<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Security\EgressHostResolver;
use App\Matomo\Sites\HttpConsentManagerDetector;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HttpConsentManagerDetectorTest extends TestCase
{
    #[DataProvider('consentManagers')]
    public function test_detects_builtin_consent_managers(
        string $content,
        string $name,
        string $url,
        bool $isConnected,
    ): void {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['https://site.example/*' => Factory::response($content)]);

        $detector = $this->detector($http);

        $this->assertSame(
            ['name' => $name, 'url' => $url, 'isConnected' => $isConnected],
            $detector->detect('https://site.example/', 20),
        );
        $http->assertSentCount(1);
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function consentManagers(): iterable
    {
        yield 'Complianz connected' => [
            "complianz-gdpr if (!cmplz_in_array( 'statistics', consentedCategories )) {\n\t\t_paq.push(['forgetCookieConsentGiven']);",
            'Complianz',
            'https://matomo.org/faq/how-to/using-complianz-for-wordpress-consent-manager-with-matomo',
            true,
        ];
        yield 'CookieYes disconnected' => [
            'script from cookieyes.com',
            'CookieYes',
            'https://matomo.org/faq/how-to/using-cookieyes-consent-manager-with-matomo',
            false,
        ];
        yield 'Cookiebot connected' => [
            "cookiebot.com typeof _paq === 'undefined' || typeof Cookiebot === 'undefined'",
            'Cookiebot',
            'https://matomo.org/faq/how-to/using-cookiebot-consent-manager-with-matomo',
            true,
        ];
        yield 'Klaro alternative signatures' => [
            "kiprotect.com title: 'Matomo',",
            'Klaro',
            'https://matomo.org/faq/how-to/using-klaro-consent-manager-with-matomo',
            true,
        ];
        yield 'Osano disconnected' => [
            'cdn.osano.com',
            'Osano',
            'https://matomo.org/faq/how-to/using-osano-consent-manager-with-matomo',
            false,
        ];
        yield 'Tarte au Citron connected' => [
            'tarteaucitron.js tarteaucitron.user.matomoHost',
            'Tarte au Citron',
            'https://matomo.org/faq/how-to/using-tarte-au-citron-consent-manager-with-matomo',
            true,
        ];
    }

    public function test_caches_a_negative_detection_for_one_site_url(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['https://site.example/*' => Factory::response('<html>No manager</html>')]);

        $detector = $this->detector($http);

        $this->assertNull($detector->detect('https://site.example/', 20));
        $this->assertNull($detector->detect('https://site.example/', 20));
        $http->assertSentCount(1);
    }

    public function test_revalidates_and_blocks_a_private_redirect_target(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake([
            'https://site.example/*' => Factory::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data',
            ]),
        ]);
        $detector = $this->detector($http);

        $this->assertNull($detector->detect('https://site.example/', 20));
        $http->assertSentCount(1);
    }

    public function test_does_not_follow_a_not_modified_response(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake([
            'https://site.example/*' => Factory::response('', 304, [
                'Location' => 'https://site.example/unexpected',
            ]),
        ]);
        $detector = $this->detector($http);

        $this->assertNull($detector->detect('https://site.example/', 20));
        $http->assertSentCount(1);
    }

    public function test_does_not_request_a_site_when_internet_features_are_disabled(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();

        $detector = $this->detector($http, false);

        $this->assertNull($detector->detect('https://site.example/', 20));
        $http->assertNothingSent();
    }

    public function test_refuses_to_bypass_a_configured_outbound_proxy(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();

        $detector = $this->detector($http, outboundProxyHost: 'proxy.example');

        $this->assertNull($detector->detect('https://site.example/', 20));
        $http->assertNothingSent();
    }

    public function test_allows_a_host_excluded_from_the_configured_outbound_proxy(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(['https://site.example/*' => Factory::response('cookieyes.com')]);

        $detector = $this->detector(
            $http,
            outboundProxyHost: 'proxy.example',
            outboundProxyExcludedHosts: ['site.example'],
        );

        $this->assertSame('CookieYes', $detector->detect('https://site.example/', 20)['name'] ?? null);
        $http->assertSentCount(1);
    }

    public function test_returns_a_cached_detection_when_internet_features_are_disabled(): void
    {
        $cache = new Repository(new ArrayStore);
        $enabledHttp = new Factory;
        $enabledHttp->preventStrayRequests();
        $enabledHttp->fake([
            'https://site.example/*' => Factory::response('cookieyes.com'),
        ]);
        $enabled = $this->detector($enabledHttp, cache: $cache);
        $expected = [
            'name' => 'CookieYes',
            'url' => 'https://matomo.org/faq/how-to/using-cookieyes-consent-manager-with-matomo',
            'isConnected' => false,
        ];

        $this->assertSame($expected, $enabled->detect('https://site.example/', 20));

        $disabledHttp = new Factory;
        $disabledHttp->preventStrayRequests();

        $disabled = $this->detector($disabledHttp, false, $cache);

        $this->assertSame($expected, $disabled->detect('https://site.example/', 20));
        $disabledHttp->assertNothingSent();
    }

    /**
     * @param  list<string>  $outboundProxyExcludedHosts
     */
    private function detector(
        Factory $http,
        bool $internetFeaturesEnabled = true,
        ?Repository $cache = null,
        ?string $outboundProxyHost = null,
        array $outboundProxyExcludedHosts = [],
    ): HttpConsentManagerDetector {
        return new HttpConsentManagerDetector(
            http: $http,
            cache: $cache ?? new Repository(new ArrayStore),
            logger: new NullLogger,
            hosts: new EgressHostResolver(
                resolver: static fn (string $host): array => $host === 'site.example'
                    ? ['93.184.216.34']
                    : [],
            ),
            internetFeaturesEnabled: $internetFeaturesEnabled,
            outboundProxyHost: $outboundProxyHost,
            outboundProxyExcludedHosts: $outboundProxyExcludedHosts,
        );
    }
}
