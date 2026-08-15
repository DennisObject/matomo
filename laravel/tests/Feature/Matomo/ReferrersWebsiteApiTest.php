<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ReferrersWebsiteApiTest extends TestCase
{
    public function test_returns_websites_with_segments_metrics_and_expanded_urls(): void
    {
        $this->bindViewAccess();
        $this->bindRecords([
            'Referrers_urlByWebsite' => [
                $this->row('example.com', 3, 6, 4),
                $this->row('news.example', 1, 2),
            ],
            'Referrers_urlByWebsite_4' => [
                $this->row('https://example.com/a?x=1&amp;y=2', 2, 4),
                $this->row('https://example.com/b', 1, 2),
            ],
        ]);

        $this->get($this->url('getWebsites').'&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.segment', 'referrerName==example.com')
            ->assertJsonPath('0.nb_visits_percent_of_total', '75%')
            ->assertJsonPath('0.idsubdatatable', 4)
            ->assertJsonPath('0.subtable.0.label', 'https://example.com/a?x=1&y=2')
            ->assertJsonPath('0.subtable.0.url', 'https://example.com/a?x=1&y=2')
            ->assertJsonPath('0.subtable.0.segment', 'referrerUrl==https%3A%2F%2Fexample.com%2Fa%3Fx%3D1%26y%3D2');
    }

    public function test_returns_a_url_subtable_directly(): void
    {
        $this->bindViewAccess();
        $this->bindRecords([
            'Referrers_urlByWebsite' => [$this->row('example.com', 2, 4, 9)],
            'Referrers_urlByWebsite_9' => [$this->row('https://example.com/path', 2, 4)],
        ]);

        $this->get($this->url('getUrlsFromWebsiteId').'&idSubtable=9')
            ->assertOk()
            ->assertJsonPath('0.label', 'path')
            ->assertJsonPath('0.url', 'https://example.com/path');
    }

    public function test_flattens_urls_with_legacy_dimension_labels(): void
    {
        $this->bindViewAccess();
        $this->bindRecords([
            'Referrers_urlByWebsite' => [$this->row('example.com', 3, 6, 4)],
            'Referrers_urlByWebsite_4' => [
                $this->row('https://example.com/', 1, 2),
                $this->row('https://example.com/news/latest', 2, 4),
            ],
        ]);

        $this->get($this->url('getWebsites').'&flat=1&show_dimensions=1')
            ->assertOk()
            ->assertJsonPath('0.label', 'example.com/index')
            ->assertJsonPath('0.Referrers_Website', 'example.com')
            ->assertJsonPath('0.Referrers_WebsitePage', 'index')
            ->assertJsonPath('1.label', 'example.com/news/latest')
            ->assertJsonPath('1.nb_visits_percent_of_total', '66.7%');
    }

    public function test_requires_a_positive_url_subtable_id(): void
    {
        $this->bindViewAccess();

        $this->get($this->url('getUrlsFromWebsiteId'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'idSubtable'.");
    }

    public function test_checks_access_before_reading_website_archives(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->never())->method('records');
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getWebsites'))->assertUnauthorized();
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(
            HierarchicalBlobArchiveRepository::class,
            $this->createStub(HierarchicalBlobArchiveRepository::class),
        );
    }

    /**
     * @param  array<string, list<array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}>>  $records
     */
    private function bindRecords(array $records): void
    {
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([
            7 => ['2026-08-14,2026-08-14' => $records],
        ]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
    }

    /**
     * @return array{columns: array<string, float|int|string|null>, metadata: array<string, float|int|string|null>, subtableId: int|null}
     */
    private function row(string $label, int $visits, int $actions, ?int $subtableId = null): array
    {
        return [
            'columns' => [
                'label' => $label,
                'nb_visits' => $visits,
                'nb_actions' => $actions,
                'nb_visits_converted' => 0,
                'bounce_count' => $visits,
            ],
            'metadata' => [],
            'subtableId' => $subtableId,
        ];
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=Referrers.{$method}&idSite=7".
            '&period=day&date=2026-08-14&format=json&token_auth=view-token';
    }
}
