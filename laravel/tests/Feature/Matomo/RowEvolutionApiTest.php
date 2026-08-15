<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use Tests\TestCase;

final class RowEvolutionApiTest extends TestCase
{
    public function test_returns_native_report_evolution_envelope(): void
    {
        $this->bindAccess(true);
        $response = $this->get($this->url())->assertOk()->json();
        $this->assertSame('nb_visits', $response['label'] ?? null);
        $this->assertIsArray($response['reportData'] ?? null);
        $this->assertSame('Visits', $response['metadata']['metrics']['nb_visits']['name'] ?? null);
    }

    public function test_rejects_site_without_view_access(): void
    {
        $this->bindAccess(false);
        $this->get($this->url())->assertUnauthorized();
    }

    private function bindAccess(bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(): string
    {
        return '/index.php?module=API&method=API.getRowEvolution&format=json&idSite=1'.
            '&period=day&date=2026-08-14,2026-08-15&apiModule=VisitsSummary&apiAction=get&column=nb_visits';
    }
}
