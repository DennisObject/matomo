<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use Tests\TestCase;

final class ApiOverviewTest extends TestCase
{
    public function test_combines_native_get_reports_and_filters_columns(): void
    {
        $this->bindAccess(true);

        $response = $this->get($this->url().'&columns=nb_visits,nb_actions')->assertOk()->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('nb_visits', $response);
        $this->assertArrayHasKey('nb_actions', $response);
        $this->assertArrayNotHasKey('bounce_count', $response);
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
        $authorizer->method('hasSomeViewAccess')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(): string
    {
        return '/index.php?module=API&method=API.get&format=json&idSite=1&period=day&date=2026-08-15';
    }
}
