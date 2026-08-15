<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use Tests\TestCase;

final class SegmentsMetadataApiTest extends TestCase
{
    public function test_returns_native_segment_catalog_and_active_site_dimensions(): void
    {
        $this->bindAccess('alice', true);
        $dimensions = $this->createStub(CustomDimensionRepository::class);
        $dimensions->method('configuredForSite')->with(7)->willReturn([
            [
                'idcustomdimension' => '3', 'idsite' => '7', 'name' => 'Account tier',
                'description' => '', 'index' => '1', 'scope' => 'visit', 'active' => true,
                'extractions' => [], 'case_sensitive' => true,
            ],
        ]);
        $this->app->instance(CustomDimensionRepository::class, $dimensions);

        $response = $this->get($this->url().'&idSites=7')->assertOk();
        $segments = $response->json();
        $this->assertIsArray($segments);
        $this->assertSame('dimension', $this->segment($segments, 'browserName')['type'] ?? null);
        $this->assertSame('Account tier', $this->segment($segments, 'dimension3')['name'] ?? null);
        $this->assertNotNull($this->segment($segments, 'visitorId'));
    }

    public function test_anonymous_metadata_hides_restricted_segments(): void
    {
        $this->bindAccess('anonymous', true);
        $response = $this->get($this->url())->assertOk();
        $segments = $response->json();
        $this->assertIsArray($segments);
        $this->assertNull($this->segment($segments, 'visitorId'));
    }

    public function test_rejects_any_site_without_view_access(): void
    {
        $this->bindAccess('alice', false);
        $this->get($this->url().'&idSites=7,8')->assertUnauthorized();
    }

    private function bindAccess(string $login, bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSomeViewAccess')->willReturn($view);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(): string
    {
        return '/index.php?module=API&method=API.getSegmentsMetadata&format=json&token_auth=token';
    }

    /**
     * @param  array<mixed>  $segments
     * @return array<string, mixed>|null
     */
    private function segment(array $segments, string $name): ?array
    {
        foreach ($segments as $segment) {
            if (is_array($segment) && ($segment['segment'] ?? null) === $name) {
                return $segment;
            }
        }

        return null;
    }
}
