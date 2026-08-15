<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Api\SitesManagerTrackingCodeRequest;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Sites\Events\ImageTrackingCodeGenerating;
use App\Matomo\Sites\Events\JavascriptTrackingCodeGenerating;
use App\Matomo\Sites\SiteRepository;
use App\Matomo\Sites\SiteTrackingCodeGenerator;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class SiteTrackingCodeGeneratorTest extends TestCase
{
    public function test_generates_option_rich_javascript_with_current_endpoints(): void
    {
        $generator = $this->generator(null, [
            'https://www.example.test/path/',
            'https://shop.example.test/',
        ]);

        $code = $generator->javascript($this->request(
            mergeSubdomains: true,
            groupPageTitlesByDomain: true,
            mergeAliasUrls: true,
            visitorCustomVariables: [['role', '</script><script>alert(1)</script>']],
            pageCustomVariables: [['section', 'news']],
            campaignNameParameter: 'campaign_name',
            campaignKeywordParameter: 'campaign_keyword',
            doNotTrack: true,
            disableCookies: true,
            trackNoScript: true,
            crossDomain: true,
            excludedQueryParameters: ['secret', 'token'],
            excludedReferrers: ['ignored.test'],
            disableCampaignParameters: true,
        ));

        self::assertStringContainsString('u="//analytics.example.test/matomo/"', $code);
        self::assertStringContainsString("u+'matomo.php'", $code);
        self::assertStringContainsString("u+'matomo.js'", $code);
        self::assertStringContainsString('setCookieDomain", "*.www.example.test/path"', $code);
        self::assertStringContainsString('setDomains", ["*.www.example.test/path","*.shop.example.test"]', $code);
        self::assertStringContainsString('enableCrossDomainLinking', $code);
        self::assertStringContainsString('disableCampaignParameters', $code);
        self::assertStringContainsString('setExcludedQueryParams", ["secret","token"]', $code);
        self::assertStringContainsString('setExcludedReferrers", ["ignored.test"]', $code);
        self::assertStringContainsString('disableCookies', $code);
        self::assertStringContainsString('<noscript>', $code);
        self::assertStringNotContainsString('</script><script>alert(1)</script>', $code);
        self::assertStringContainsString('<\/script><script>alert(1)<\/script>', $code);
    }

    public function test_preserves_old_endpoint_and_force_override(): void
    {
        $generator = $this->generator('3.6.1');

        self::assertStringContainsString("u+'piwik.js'", $generator->javascript($this->request()));
        self::assertStringContainsString(
            "u+'matomo.js'",
            $generator->javascript($this->request(forceMatomoEndpoint: true)),
        );
    }

    public function test_javascript_event_can_change_generated_code(): void
    {
        $events = new Dispatcher;
        $events->listen(JavascriptTrackingCodeGenerating::class, function (JavascriptTrackingCodeGenerating $event): void {
            self::assertSame(7, $event->parameters['siteId']);
            $event->code['optionsBeforeTrackerUrl'] = "_paq.push(['requireConsent']);\n    ";
            $event->code['matomoJsFilename'] = 'custom.js';
        });

        $code = $this->generator(null, [], $events)->javascript($this->request());

        self::assertStringContainsString("_paq.push(['requireConsent'])", $code);
        self::assertStringContainsString("u+'custom.js'", $code);
    }

    public function test_generates_encoded_image_code_and_dispatches_mutable_event(): void
    {
        $events = new Dispatcher;
        $events->listen(ImageTrackingCodeGenerating::class, function (ImageTrackingCodeGenerating $event): void {
            $event->host = 'collector.example.test/base';
            $event->parameters['dimension1'] = 'yes';
        });
        $code = $this->generator(null, [], $events)->image($this->request(
            actionName: 'A &amp; B',
            goalId: 4,
            revenue: 12.5,
        ), true);

        self::assertStringContainsString('&lt;!-- Matomo Image Tracker--&gt;', $code);
        self::assertStringContainsString('https://collector.example.test/base/matomo.php?', html_entity_decode($code));
        self::assertStringContainsString('action_name=A%2B%2526%2BB', html_entity_decode($code));
        self::assertStringContainsString('idgoal=4', html_entity_decode($code));
        self::assertStringContainsString('revenue=12.5', html_entity_decode($code));
        self::assertStringContainsString('dimension1=yes', html_entity_decode($code));
    }

    /** @param list<string> $urls */
    private function generator(?string $version, array $urls = [], ?Dispatcher $events = null): SiteTrackingCodeGenerator
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('urls')->willReturn($urls);
        $options = $this->createStub(OptionRepository::class);
        $options->method('value')->willReturnCallback(
            static fn (string $name): ?string => $name === 'version_core' ? $version : null,
        );

        return new SiteTrackingCodeGenerator($sites, $options, $events ?? new Dispatcher);
    }

    private function request(mixed ...$overrides): SitesManagerTrackingCodeRequest
    {
        return new SitesManagerTrackingCodeRequest(...array_replace([
            'siteId' => 7,
            'matomoUrl' => 'https://analytics.example.test/matomo/',
            'mergeSubdomains' => false,
            'groupPageTitlesByDomain' => false,
            'mergeAliasUrls' => false,
            'visitorCustomVariables' => [],
            'pageCustomVariables' => [],
            'campaignNameParameter' => '',
            'campaignKeywordParameter' => '',
            'doNotTrack' => false,
            'disableCookies' => false,
            'trackNoScript' => false,
            'crossDomain' => false,
            'forceMatomoEndpoint' => false,
            'excludedQueryParameters' => [],
            'excludedReferrers' => [],
            'disableCampaignParameters' => false,
            'actionName' => null,
            'goalId' => false,
            'revenue' => false,
        ], $overrides));
    }
}
