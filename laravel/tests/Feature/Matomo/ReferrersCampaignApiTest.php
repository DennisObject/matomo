<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ReferrersCampaignApiTest extends TestCase
{
    public function test_returns_campaign_rows_with_processed_metrics_and_segments(): void
    {
        $this->bindViewAccess();
        $this->bindRecords([
            'Referrers_keywordByCampaign' => [
                $this->row('summer sale', 3, 6, 1, 30),
                $this->row('newsletter', 1, 2, null, 5),
            ],
            'Referrers_keywordByCampaign_1' => [
                $this->row('shoes', 2, 4),
                $this->row('hats', 1, 2),
            ],
        ]);

        $this->get($this->url('getCampaigns'))
            ->assertOk()
            ->assertJsonPath('0.label', 'summer sale')
            ->assertJsonPath('0.nb_visits_percent_of_total', '75%')
            ->assertJsonPath('0.nb_actions_per_visit', 2)
            ->assertJsonPath('0.avg_time_on_site', 10)
            ->assertJsonPath('0.bounce_rate', '33.3%')
            ->assertJsonPath('0.idsubdatatable', 1)
            ->assertJsonPath('0.segment', 'referrerType==campaign;referrerName==summer+sale');
    }

    public function test_expands_campaign_keyword_subtables(): void
    {
        $this->bindViewAccess();
        $this->bindRecords([
            'Referrers_keywordByCampaign' => [$this->row('summer sale', 3, 6, 1)],
            'Referrers_keywordByCampaign_1' => [$this->row('shoes', 2, 4)],
        ]);

        $this->get($this->url('getCampaigns').'&expanded=1')
            ->assertOk()
            ->assertJsonPath('0.subtable.0.label', 'shoes')
            ->assertJsonPath(
                '0.subtable.0.segment',
                'referrerName==summer+sale;referrerType==campaign;referrerKeyword==shoes',
            );
    }

    public function test_returns_a_campaign_keyword_subtable_directly(): void
    {
        $this->bindViewAccess();
        $this->bindRecords([
            'Referrers_keywordByCampaign' => [$this->row('summer sale', 3, 6, 9)],
            'Referrers_keywordByCampaign_9' => [$this->row('shoes & hats', 2, 4)],
        ]);

        $this->get($this->url('getKeywordsFromCampaignId').'&idSubtable=9')
            ->assertOk()
            ->assertJsonPath('0.label', 'shoes & hats')
            ->assertJsonPath(
                '0.segment',
                'referrerName==summer+sale;referrerType==campaign;referrerKeyword==shoes+%26+hats',
            );
    }

    public function test_requires_a_subtable_id(): void
    {
        $this->bindViewAccess();

        $this->get($this->url('getKeywordsFromCampaignId'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'idSubtable'.");
    }

    public function test_rejects_a_non_positive_subtable_id(): void
    {
        $this->bindViewAccess();

        $this->get($this->url('getKeywordsFromCampaignId').'&idSubtable=-1')
            ->assertBadRequest()
            ->assertJsonPath('message', "The parameter 'idSubtable' has an invalid value.");
    }

    public function test_checks_access_before_reading_campaign_archives(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->never())->method('records');
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getCampaigns'))->assertUnauthorized();
    }

    public function test_renders_campaigns_as_rss_for_multiple_dates(): void
    {
        $this->bindViewAccess();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $sites->method('details')->willReturn(['name' => 'Example']);
        $this->app->instance(SiteRepository::class, $sites);
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([
            7 => [
                '2026-08-13,2026-08-13' => [
                    'Referrers_keywordByCampaign' => [$this->row('summer', 2, 4)],
                ],
                '2026-08-14,2026-08-14' => [
                    'Referrers_keywordByCampaign' => [$this->row('summer', 3, 6)],
                ],
            ],
        ]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $response = $this->get(str_replace(
            ['date=2026-08-14', 'format=json'],
            ['date=2026-08-13,2026-08-14', 'format=rss'],
            $this->url('getCampaigns'),
        ))->assertOk()->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('<rss version="2.0">', $content);
        $this->assertStringContainsString('summer', $content);
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
    private function row(
        string $label,
        int $visits,
        int $actions,
        ?int $subtableId = null,
        int $length = 0,
    ): array {
        return [
            'columns' => [
                'label' => $label,
                'nb_visits' => $visits,
                'nb_actions' => $actions,
                'sum_visit_length' => $length,
                'bounce_count' => 1,
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
