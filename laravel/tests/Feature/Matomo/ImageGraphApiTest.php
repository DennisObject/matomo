<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Reporting\VisitsSummaryArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Tests\TestCase;

final class ImageGraphApiTest extends TestCase
{
    public function test_requires_view_access_to_the_requested_site(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url())
            ->assertUnauthorized()->assertJsonPath('message', 'You do not have view access to this site.');
    }

    public function test_rejects_unknown_graph_types_at_the_request_boundary(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url().'&graphType=unknown')
            ->assertBadRequest()->assertJsonPath('message', 'The graph type is invalid.');
    }

    public function test_renders_a_laravel_source_report_as_png(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $archives = $this->createStub(VisitsSummaryArchiveRepository::class);
        $archives->method('metrics')->willReturn([
            7 => ['2026-08-14,2026-08-14' => [
                'nb_uniq_visitors' => 2, 'nb_users' => 1, 'nb_visits' => 4, 'nb_actions' => 10,
                'nb_visits_converted' => 1, 'bounce_count' => 1, 'sum_visit_length' => 20, 'max_actions' => 5,
            ]],
        ]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $this->app->instance(SiteRepository::class, $sites);
        $this->app->instance(VisitsSummaryArchiveRepository::class, $archives);

        $this->get($this->url().'&columns=nb_visits&width=320&height=180')
            ->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_rejects_mutating_source_methods(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get(str_replace('apiAction=get', 'apiAction=deleteSite', $this->url()))
            ->assertBadRequest()->assertJsonPath(
                'message',
                'ImageGraph only accepts read-only report methods.',
            );
    }

    private function url(): string
    {
        return '/index.php?module=API&method=ImageGraph.get&idSite=7&period=day&date=2026-08-14'.
            '&apiModule=VisitsSummary&apiAction=get&format=json&token_auth=view-token';
    }
}
