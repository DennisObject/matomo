<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use Tests\TestCase;

class ExampleReportApiTest extends TestCase
{
    public function test_returns_example_report_after_view_access_check(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url())
            ->assertOk()
            ->assertExactJson([['nb_visits' => 5]]);
    }

    public function test_rejects_missing_site_and_denied_access(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url(['idSite' => '']))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'idSite'.");
        $this->get($this->url())
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                "You can't access this resource as it requires 'view' access for the website id = 7.",
            );
    }

    /** @param array<string, string> $parameters */
    private function url(array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'ExampleReport.getExampleReport',
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-14',
            'format' => 'json',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
    }
}
