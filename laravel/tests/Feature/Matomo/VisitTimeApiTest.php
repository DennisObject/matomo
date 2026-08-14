<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class VisitTimeApiTest extends TestCase
{
    public function test_returns_local_time_rows_with_processed_metrics_and_segments(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $segments = $this->createMock(SegmentHashResolver::class);
        $segments->expects($this->once())->method('resolve')->with('countryCode==NZ')->willReturn('segment-hash');
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), 'segment-hash', 'VisitTime_localTime')
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $this->archiveRow(2, 1, 4),
                $this->archiveRow(1, 3, 6),
            ]]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(SegmentHashResolver::class, $segments);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitTime.getVisitInformationPerLocalTime'.
            '&idSite=7&period=day&date=2026-08-14&segment=countryCode%3D%3DNZ'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            [
                'label' => '01',
                'nb_visits' => 3,
                'nb_actions' => 6,
                'nb_visits_percent_of_total' => '75%',
                'nb_actions_percent_of_total' => '60%',
                'segment' => 'visitLocalHour==1',
            ],
            [
                'label' => '02',
                'nb_visits' => 1,
                'nb_actions' => 4,
                'nb_visits_percent_of_total' => '25%',
                'nb_actions_percent_of_total' => '40%',
                'segment' => 'visitLocalHour==2',
            ],
        ]);
    }

    public function test_server_time_uses_the_server_hour_segment_and_hides_metadata_when_requested(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('Pacific/Auckland');
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            $this->archiveRow(4, 2, 3),
        ]]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitTime.getVisitInformationPerServerTime'.
            '&idSite=7&period=day&date=2026-08-14&showMetadata=0'.
            '&format=xml&token_auth=view-token',
        )->assertOk()->assertContent(<<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<result>
	<row>
		<label>04</label>
		<nb_visits>2</nb_visits>
		<nb_actions>3</nb_actions>
		<nb_visits_percent_of_total>100%</nb_visits_percent_of_total>
		<nb_actions_percent_of_total>100%</nb_actions_percent_of_total>
	</row>
</result>
XML);
    }

    public function test_renders_multiple_dates_as_rss(): void
    {
        $this->bindViewAccess([7]);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $sites->method('details')->willReturn(['name' => 'Example']);
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([7 => [
            '2026-08-13,2026-08-13' => [$this->archiveRow(1, 2, 3)],
            '2026-08-14,2026-08-14' => [$this->archiveRow(2, 4, 5)],
        ]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $response = $this->get(
            '/index.php?module=API&method=VisitTime.getVisitInformationPerLocalTime'.
            '&idSite=7&period=day&date=2026-08-13,2026-08-14'.
            '&format=rss&token_auth=view-token',
        )->assertOk()->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('<title>Example on 2026-08-14</title>', $content);
        $this->assertStringContainsString('&lt;strong&gt;label&lt;/strong&gt;', $content);
        $this->assertStringContainsString('&lt;td&gt;02&lt;/td&gt;', $content);
    }

    /** @return array{columns: array<string, int>, metadata: array<string, string>} */
    private function archiveRow(int $hour, int $visits, int $actions): array
    {
        return [
            'columns' => ['label' => $hour, 'nb_visits' => $visits, 'nb_actions' => $actions],
            'metadata' => [],
        ];
    }

    /** @param list<int> $siteIds */
    private function bindViewAccess(array $siteIds): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(count($siteIds)))
            ->method('hasViewAccessToSite')
            ->with(
                $this->isInstanceOf(ApiAuthentication::class),
                $this->isType('int'),
            )
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
