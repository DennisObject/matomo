<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitorInterestApiTest extends TestCase
{
    #[DataProvider('reports')]
    public function test_formats_engagement_ranges(
        string $method,
        string $recordName,
        string $rawLabel,
        string $label,
        string $segment,
    ): void {
        $this->bindViewAccess();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', $recordName)
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                ['columns' => ['label' => $rawLabel, 'nb_visits' => 3], 'metadata' => []],
                ['columns' => ['label' => '1-1', 'nb_visits' => 1], 'metadata' => []],
            ]]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $content = $this->get(
            "/index.php?module=API&method={$method}&idSite=7&period=day".
            '&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->getContent();
        $response = is_string($content) ? json_decode($content, true, flags: JSON_THROW_ON_ERROR) : null;
        $this->assertIsArray($response);
        $row = null;

        foreach ($response as $candidate) {
            if (is_array($candidate) && ($candidate['label'] ?? null) === $label) {
                $row = $candidate;
                break;
            }
        }

        $this->assertIsArray($row);
        $this->assertSame('75%', $row['nb_visits_percent_of_total']);
        $this->assertSame($segment, $row['segment']);
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function reports(): iterable
    {
        yield 'duration' => [
            'VisitorInterest.getNumberOfVisitsPerVisitDuration',
            'VisitorInterest_timeGap',
            '120-240',
            '2-4 min',
            'visitDuration>=120;visitDuration<=240',
        ];
        yield 'pages' => [
            'VisitorInterest.getNumberOfVisitsPerPage',
            'VisitorInterest_pageGap',
            '6-7',
            '6-7 pages',
            'actions>=6;actions<=7',
        ];
        yield 'days since last visit' => [
            'VisitorInterest.getNumberOfVisitsByDaysSinceLast',
            'VisitorInterest_daysSinceLastVisit',
            'General_NewVisits',
            'New visits',
            'visitorType==new',
        ];
        yield 'visit count' => [
            'VisitorInterest.getNumberOfVisitsByVisitCount',
            'VisitorInterest_visitsByVisitCount',
            '201+',
            '201+ visits',
            'visitCount>=201',
        ];
    }

    public function test_hides_segment_metadata_when_requested(): void
    {
        $this->bindViewAccess();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([7 => ['2026-08-14,2026-08-14' => [[
            'columns' => ['label' => '1-1', 'nb_visits' => 1],
            'metadata' => [],
        ]]]]);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=VisitorInterest.getNumberOfVisitsPerPage'.
            '&idSite=7&period=day&date=2026-08-14&showMetadata=0'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([[
            'label' => '1 page',
            'nb_visits' => 1,
            'nb_visits_percent_of_total' => '100%',
        ]]);
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
