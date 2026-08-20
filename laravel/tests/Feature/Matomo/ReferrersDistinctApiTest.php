<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReferrersDistinctApiTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function methods(): iterable
    {
        yield 'search engines' => ['getNumberOfDistinctSearchEngines', 'Referrers_distinctSearchEngines'];
        yield 'social networks' => ['getNumberOfDistinctSocialNetworks', 'Referrers_distinctSocialNetworks'];
        yield 'keywords' => ['getNumberOfDistinctKeywords', 'Referrers_distinctKeywords'];
        yield 'campaigns' => ['getNumberOfDistinctCampaigns', 'Referrers_distinctCampaigns'];
        yield 'websites' => ['getNumberOfDistinctWebsites', 'Referrers_distinctWebsites'];
        yield 'AI assistants' => ['getNumberOfDistinctAIAssistants', 'Referrers_distinctAIAssistants'];
        yield 'website URLs' => ['getNumberOfDistinctWebsitesUrls', 'Referrers_distinctWebsitesUrls'];
    }

    #[DataProvider('methods')]
    public function test_reads_each_distinct_metric_from_the_referrers_archive(
        string $method,
        string $metric,
    ): void {
        $this->bindViewAccess();
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())->method('pluginMetrics')->with(
            [7],
            $this->isType('array'),
            '',
            [$metric],
            'Referrers',
        )->willReturn([
            7 => ['2026-08-14,2026-08-14' => [$metric => 3]],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url($method))->assertOk()->assertContent('3');
    }

    public function test_preserves_segment_and_multi_date_response_context(): void
    {
        $this->bindViewAccess();
        $metric = 'Referrers_distinctCampaigns';
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())->method('pluginMetrics')->with(
            [7],
            $this->isType('array'),
            md5('countryCode==fr'),
            [$metric],
            'Referrers',
        )->willReturn([
            7 => [
                '2026-08-13,2026-08-13' => [$metric => 2],
                '2026-08-14,2026-08-14' => [$metric => 4],
            ],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url('getNumberOfDistinctCampaigns', '2026-08-13,2026-08-14').
            '&segment=countryCode%3D%3Dfr')
            ->assertOk()
            ->assertExactJson(['2026-08-13' => 2, '2026-08-14' => 4]);
    }

    public function test_rejects_site_access_before_reading_the_archive(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->never())->method('pluginMetrics');
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url('getNumberOfDistinctWebsites'))
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                "You can't access this resource as it requires 'view' access for the website id = 7.",
            );
    }

    public function test_returns_a_multi_site_map_for_all_visible_sites(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn([7, 8]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $metric = 'Referrers_distinctWebsites';
        $numbers = $this->createStub(NumericArchiveRepository::class);
        $numbers->method('pluginMetrics')->willReturn([
            7 => ['2026-08-14,2026-08-14' => [$metric => 3]],
            8 => ['2026-08-14,2026-08-14' => [$metric => 5]],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get(str_replace('idSite=7', 'idSite=all', $this->url('getNumberOfDistinctWebsites')))
            ->assertOk()
            ->assertExactJson(['7' => 3, '8' => 5]);
    }

    public function test_renders_a_distinct_metric_as_rss(): void
    {
        $this->bindViewAccess();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $sites->method('details')->willReturn(['name' => 'Example']);
        $this->app->instance(SiteRepository::class, $sites);
        $metric = 'Referrers_distinctSearchEngines';
        $numbers = $this->createStub(NumericArchiveRepository::class);
        $numbers->method('pluginMetrics')->willReturn([
            7 => [
                '2026-08-13,2026-08-13' => [$metric => 2],
                '2026-08-14,2026-08-14' => [$metric => 3],
            ],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $response = $this->get(str_replace(
            'format=json',
            'format=rss',
            $this->url('getNumberOfDistinctSearchEngines', '2026-08-13,2026-08-14'),
        ))->assertOk()->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('<rss version="2.0">', $content);
        $this->assertStringContainsString('&lt;td&gt;3&lt;/td&gt;', $content);
        $this->assertStringNotContainsString('matomo.org', $content);
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function url(string $method, string $date = '2026-08-14'): string
    {
        return "/index.php?module=API&method=Referrers.{$method}&idSite=7".
            "&period=day&date={$date}&format=json&token_auth=view-token";
    }
}
