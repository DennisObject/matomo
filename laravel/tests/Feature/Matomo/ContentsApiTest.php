<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ContentsApiTest extends TestCase
{
    public function test_returns_content_name_metrics_and_segments(): void
    {
        $this->bindAccessAndSite();
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', 'Contents_name_piece')
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $this->row('Image Ad', 8, 8, 2),
                $this->row('Text Ad', 6, 6, 4),
                $this->row('Piwik_ContentPieceNotSet', 2, 2, 0),
            ]]]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=Contents.getContentNames&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            [
                'label' => 'Image Ad',
                'nb_visits' => 8,
                'nb_impressions' => 8,
                'nb_interactions' => 2,
                'nb_visits_percent_of_total' => '50%',
                'interaction_rate' => '25%',
                'segment' => 'contentName==Image+Ad',
            ],
            [
                'label' => 'Text Ad',
                'nb_visits' => 6,
                'nb_impressions' => 6,
                'nb_interactions' => 4,
                'nb_visits_percent_of_total' => '37.5%',
                'interaction_rate' => '66.67%',
                'segment' => 'contentName==Text+Ad',
            ],
            [
                'label' => 'Content Piece not defined',
                'nb_visits' => 2,
                'nb_impressions' => 2,
                'nb_interactions' => 0,
                'nb_visits_percent_of_total' => '12.5%',
                'interaction_rate' => '0%',
            ],
        ]);
    }

    public function test_reads_requested_piece_subtable_without_top_level_segment(): void
    {
        $this->bindAccessAndSite();
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', 'Contents_piece_name_42')
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $this->row('Hero name', 3, 3, 1),
            ]]]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=Contents.getContentPieces&idSite=7'.
            '&period=day&date=2026-08-14&idSubtable=42&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([[
            'label' => 'Hero name',
            'nb_visits' => 3,
            'nb_impressions' => 3,
            'nb_interactions' => 1,
            'nb_visits_percent_of_total' => '100%',
            'interaction_rate' => '33.33%',
        ]]);
    }

    public function test_rejects_invalid_subtable_id(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get(
            '/index.php?module=API&method=Contents.getContentNames&idSite=7'.
            '&period=day&date=2026-08-14&idSubtable=invalid&format=json&token_auth=view-token',
        )->assertStatus(400)->assertExactJson([
            'result' => 'error',
            'message' => "The parameter 'idSubtable' has an invalid value.",
        ]);
    }

    /**
     * @return array{columns: array{label: string, nb_visits: int, nb_impressions: int, nb_interactions: int}, metadata: array{}}
     */
    private function row(string $label, int $visits, int $impressions, int $interactions): array
    {
        return [
            'columns' => [
                'label' => $label,
                'nb_visits' => $visits,
                'nb_impressions' => $impressions,
                'nb_interactions' => $interactions,
            ],
            'metadata' => [],
        ];
    }

    private function bindAccessAndSite(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
    }
}
