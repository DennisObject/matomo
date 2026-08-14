<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class UserIdApiTest extends TestCase
{
    public function test_returns_user_metrics_percentages_and_encoded_segments(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', 'UserId_users')
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $this->row('alice smith', 3, 6),
                $this->row('bob@example.com', 1, 2),
            ]]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=UserId.getUsers&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            [
                'label' => 'alice smith',
                'nb_visits' => 3,
                'nb_actions' => 6,
                'nb_visits_percent_of_total' => '75%',
                'nb_actions_percent_of_total' => '75%',
                'segment' => 'userId==alice+smith',
            ],
            [
                'label' => 'bob@example.com',
                'nb_visits' => 1,
                'nb_actions' => 2,
                'nb_visits_percent_of_total' => '25%',
                'nb_actions_percent_of_total' => '25%',
                'segment' => 'userId==bob%40example.com',
            ],
        ]);
    }

    public function test_replaces_summary_label_without_adding_segment(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(BlobArchiveRepository::class);
        $archives->method('rows')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            $this->row(-1, 1, 1),
        ]]]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=UserId.getUsers&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([[
            'label' => 'Others',
            'nb_visits' => 1,
            'nb_actions' => 1,
            'nb_visits_percent_of_total' => '100%',
            'nb_actions_percent_of_total' => '100%',
        ]]);
    }

    /** @return array{columns: array{label: int|string, nb_visits: int, nb_actions: int}, metadata: array{}} */
    private function row(int|string $label, int $visits, int $actions): array
    {
        return [
            'columns' => ['label' => $label, 'nb_visits' => $visits, 'nb_actions' => $actions],
            'metadata' => [],
        ];
    }
}
