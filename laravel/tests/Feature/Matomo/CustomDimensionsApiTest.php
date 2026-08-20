<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use Tests\TestCase;

class CustomDimensionsApiTest extends TestCase
{
    private ApiAccessAuthorizer $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $this->authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->authorizer->method('hasSomeAdminAccess')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $this->authorizer);
        $this->app->instance(CustomDimensionRepository::class, new FakeCustomDimensionRepository);
    }

    public function test_returns_configured_dimensions_and_filters_hidden_scope_method(): void
    {
        $this->get($this->url('getConfiguredCustomDimensions', ['idSite' => 1]))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.idcustomdimension', '1')
            ->assertJsonPath('0.active', true)
            ->assertJsonPath('1.extractions.0.dimension', 'url');

        $this->get($this->url('getConfiguredCustomDimensionsHavingScope', [
            'idSite' => 1,
            'scope' => 'action',
        ]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Page category');
    }

    public function test_returns_available_scope_capacity(): void
    {
        $this->get($this->url('getAvailableScopes', ['idSite' => 1]))
            ->assertOk()
            ->assertJsonPath('0.value', 'visit')
            ->assertJsonPath('0.numSlotsAvailable', 2)
            ->assertJsonPath('0.numSlotsUsed', 1)
            ->assertJsonPath('0.numSlotsLeft', 1)
            ->assertJsonPath('0.supportsExtractions', false)
            ->assertJsonPath('1.value', 'action')
            ->assertJsonPath('1.numSlotsAvailable', 3)
            ->assertJsonPath('1.numSlotsUsed', 1)
            ->assertJsonPath('1.supportsExtractions', true);
    }

    public function test_returns_localized_extraction_dimensions(): void
    {
        $this->get($this->url('getAvailableExtractionDimensions'))
            ->assertOk()
            ->assertExactJson([
                ['value' => 'url', 'name' => 'Page URL'],
                ['value' => 'urlparam', 'name' => 'Page URL Parameter'],
                ['value' => 'action_name', 'name' => 'Page Title'],
            ]);
    }

    public function test_enforces_view_and_write_access(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getConfiguredCustomDimensions', ['idSite' => 1]))
            ->assertUnauthorized();
        $this->get($this->url('getAvailableExtractionDimensions'))
            ->assertUnauthorized();
    }

    /** @param array<string, int|string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'CustomDimensions.'.$method,
            'format' => 'json',
            'token_auth' => 'test-token',
            ...$parameters,
        ]);
    }
}

final class FakeCustomDimensionRepository implements CustomDimensionRepository
{
    public function configuredForSite(int $siteId): array
    {
        return [
            [
                'idcustomdimension' => '1',
                'idsite' => (string) $siteId,
                'name' => 'Customer type',
                'description' => '',
                'index' => '1',
                'scope' => 'visit',
                'active' => true,
                'extractions' => [],
                'case_sensitive' => true,
            ],
            [
                'idcustomdimension' => '2',
                'idsite' => (string) $siteId,
                'name' => 'Page category',
                'description' => 'Category from URL',
                'index' => '2',
                'scope' => 'action',
                'active' => true,
                'extractions' => [['dimension' => 'url', 'pattern' => '/category/(.+)']],
                'case_sensitive' => false,
            ],
        ];
    }

    public function installedIndexes(string $scope): array
    {
        return $scope === 'visit' ? [1, 2] : [1, 2, 3];
    }
}
