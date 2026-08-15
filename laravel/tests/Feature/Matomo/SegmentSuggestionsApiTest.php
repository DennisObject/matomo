<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Segments\SegmentValueRepository;
use Tests\TestCase;

final class SegmentSuggestionsApiTest extends TestCase
{
    public function test_returns_frequent_values_for_known_segment(): void
    {
        $this->bindAccess(true);
        $values = $this->createMock(SegmentValueRepository::class);
        $values->expects($this->once())->method('mostFrequent')->with(7, 'browserCode', 30)->willReturn(['FF', 'CH']);
        $this->app->instance(SegmentValueRepository::class, $values);

        $this->get($this->url('browserCode'))->assertOk()->assertExactJson(['FF', 'CH']);
    }

    public function test_rejects_unknown_segment(): void
    {
        $this->bindAccess(true);
        $this->get($this->url('notARealSegment'))->assertBadRequest();
    }

    public function test_rejects_site_without_view_access(): void
    {
        $this->bindAccess(false);
        $this->get($this->url('browserCode'))->assertUnauthorized();
    }

    private function bindAccess(bool $view): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $dimensions = $this->createStub(CustomDimensionRepository::class);
        $dimensions->method('configuredForSite')->willReturn([]);
        $this->app->instance(CustomDimensionRepository::class, $dimensions);
    }

    private function url(string $segment): string
    {
        return "/index.php?module=API&method=API.getSuggestedValuesForSegment&format=json&idSite=7&segmentName={$segment}";
    }
}
