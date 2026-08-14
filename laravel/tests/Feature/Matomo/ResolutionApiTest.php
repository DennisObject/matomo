<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\ScreenResolutionPolicy;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class ResolutionApiTest extends TestCase
{
    public function test_returns_resolution_metrics_with_segments_and_summary_label(): void
    {
        $this->bindViewAccess([7]);
        $this->bindSites();
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', 'Resolution_resolution')
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $this->row('1920x1080', 3, 6),
                $this->row(-1, 1, 2),
            ]]]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=Resolution.getResolution&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            [
                'label' => '1920x1080',
                'nb_visits' => 3,
                'nb_actions' => 6,
                'nb_visits_percent_of_total' => '75%',
                'nb_actions_percent_of_total' => '75%',
                'segment' => 'resolution==1920x1080',
            ],
            [
                'label' => 'Others',
                'nb_visits' => 1,
                'nb_actions' => 2,
                'nb_visits_percent_of_total' => '25%',
                'nb_actions_percent_of_total' => '25%',
            ],
        ]);
    }

    public function test_groups_legacy_configuration_rows_by_display_label(): void
    {
        $this->bindViewAccess([7]);
        $this->bindSites();
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            $this->row('WIN 10;CH;1920x1080', 2, 4),
            $this->row('W10 10;CH;1920x1080', 1, 2),
        ]]]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $response = $this->get(
            '/index.php?module=API&method=Resolution.getConfiguration&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk();

        $this->assertCount(1, $response->json());
        $response->assertJsonPath('0.nb_visits', 3)
            ->assertJsonPath('0.nb_actions', 6)
            ->assertJsonPath('0.nb_visits_percent_of_total', '100%');
    }

    public function test_filters_disabled_sites_and_keeps_enabled_sites(): void
    {
        $this->bindViewAccess([7, 8]);
        $this->bindSites();
        $policy = $this->createStub(ScreenResolutionPolicy::class);
        $policy->method('detectionDisabled')->willReturnCallback(fn (int $idSite): bool => $idSite === 7);
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([8], $this->isType('array'), '', 'Resolution_resolution')
            ->willReturn([]);
        $this->app->instance(ScreenResolutionPolicy::class, $policy);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=Resolution.getResolution&idSite=7,8'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson(['8' => []]);
    }

    public function test_rejects_resolution_report_when_all_sites_are_disabled(): void
    {
        $this->bindViewAccess([7]);
        $policy = $this->createStub(ScreenResolutionPolicy::class);
        $policy->method('detectionDisabled')->willReturn(true);
        $this->app->instance(ScreenResolutionPolicy::class, $policy);

        $this->get(
            '/index.php?module=API&method=Resolution.getResolution&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertStatus(400)->assertExactJson([
            'result' => 'error',
            'message' => 'Screen resolution report is disabled by compliance policy.',
        ]);
    }

    /** @return array{columns: array{label: int|string, nb_visits: int, nb_actions: int}, metadata: array{}} */
    private function row(int|string $label, int $visits, int $actions): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits, 'nb_actions' => $actions],
            'metadata' => [],
        ];
    }

    private function bindSites(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
    }

    /** @param list<int> $siteIds */
    private function bindViewAccess(array $siteIds): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly(count($siteIds)))
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), $this->isType('int'))
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
