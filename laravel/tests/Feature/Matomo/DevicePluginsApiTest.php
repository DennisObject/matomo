<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class DevicePluginsApiTest extends TestCase
{
    public function test_calculates_plugin_percentages_without_unsupported_ie_visits(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $blobs = $this->createMock(BlobArchiveRepository::class);
        $blobs->expects($this->exactly(2))->method('rows')->willReturnCallback(
            fn (array $siteIds, array $periods, string $segmentHash, string $record): array => [
                7 => ['2026-08-14,2026-08-14' => $record === 'DevicePlugins_plugin'
                    ? [$this->row('flash', 4), $this->row('java', 2)]
                    : [$this->row('IE;10.0', 2), $this->row('CH;1.0', 8)]],
            ],
        );
        $metrics = $this->createStub(VisitsSummaryArchiveRepository::class);
        $metrics->method('metrics')->willReturn([
            7 => ['2026-08-14,2026-08-14' => ['nb_visits' => 10]],
        ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $blobs);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $metrics);

        $this->get(
            '/index.php?module=API&method=DevicePlugins.getPlugin&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            [
                'label' => 'Flash',
                'nb_visits' => 4,
                'logo' => 'plugins/Morpheus/icons/dist/plugins/flash.png',
                'nb_visits_percentage' => '50%',
                'nb_visits_percent_of_total' => '66.7%',
            ],
            [
                'label' => 'Java',
                'nb_visits' => 2,
                'logo' => 'plugins/Morpheus/icons/dist/plugins/java.png',
                'nb_visits_percentage' => '25%',
                'nb_visits_percent_of_total' => '33.3%',
            ],
        ]);
    }

    /** @return array{columns: array{label: string, nb_visits: int}, metadata: array{}} */
    private function row(string $label, int $visits): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits],
            'metadata' => [],
        ];
    }
}
