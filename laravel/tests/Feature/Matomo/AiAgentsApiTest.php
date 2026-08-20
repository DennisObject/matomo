<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

class AiAgentsApiTest extends TestCase
{
    public function test_merges_ai_agent_and_human_visit_metrics_with_suffixes(): void
    {
        $this->bindAccessAndSite();
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->exactly(2))
            ->method('metrics')
            ->willReturnCallback(function (
                array $siteIds,
                array $periods,
                string $segmentHash,
                array $metrics,
            ): array {
                $aiAgent = $segmentHash === md5('aiAgentName!=');

                return [7 => ['2026-08-14,2026-08-14' => [
                    'nb_uniq_visitors' => $aiAgent ? 2 : 1,
                    'nb_users' => 0,
                    'nb_visits' => $aiAgent ? 2 : 1,
                    'nb_actions' => $aiAgent ? 4 : 3,
                    'nb_visits_converted' => 0,
                    'bounce_count' => $aiAgent ? 1 : 0,
                    'sum_visit_length' => $aiAgent ? 10 : 20,
                    'max_actions' => $aiAgent ? 2 : 3,
                ]]];
            });
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=AIAgents.get&idSite=7'.
            '&period=day&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            'nb_uniq_visitors_ai_agent' => 2,
            'nb_users_ai_agent' => 0,
            'nb_visits_ai_agent' => 2,
            'nb_actions_ai_agent' => 4,
            'nb_visits_converted_ai_agent' => 0,
            'bounce_count_ai_agent' => 1,
            'sum_visit_length_ai_agent' => 10,
            'max_actions_ai_agent' => 2,
            'bounce_rate_ai_agent' => '50%',
            'nb_actions_per_visit_ai_agent' => 2,
            'avg_time_on_site_ai_agent' => 5,
            'nb_uniq_visitors_human' => 1,
            'nb_users_human' => 0,
            'nb_visits_human' => 1,
            'nb_actions_human' => 3,
            'nb_visits_converted_human' => 0,
            'bounce_count_human' => 0,
            'sum_visit_length_human' => 20,
            'max_actions_human' => 3,
            'bounce_rate_human' => '0%',
            'nb_actions_per_visit_human' => 3,
            'avg_time_on_site_human' => 20,
        ]);
    }

    public function test_fetches_only_requested_client_type_and_appends_custom_segment(): void
    {
        $this->bindAccessAndSite();
        $archives = $this->createMock(VisitsSummaryArchiveRepository::class);
        $archives->expects($this->once())
            ->method('metrics')
            ->with(
                [7],
                $this->isType('array'),
                md5('countryCode==de;aiAgentName!='),
                ['nb_visits'],
            )
            ->willReturn([7 => ['2026-08-14,2026-08-14' => ['nb_visits' => 4]]]);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=AIAgents.get&idSite=7&period=day&date=2026-08-14'.
            '&segment=countryCode%3D%3Dde&columns=nb_visits_ai_agent'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson(['nb_visits_ai_agent' => 4]);
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
