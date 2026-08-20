<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\CustomDimensions\CustomDimensionRepository;
use App\Matomo\Segments\SegmentSuggestionPolicy;
use App\Matomo\Segments\SegmentValueRepository;
use Tests\TestCase;

final class SegmentSuggestionsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $policy = $this->createStub(SegmentSuggestionPolicy::class);
        $policy->method('enabled')->willReturn(true);
        $this->app->instance(SegmentSuggestionPolicy::class, $policy);
    }

    public function test_returns_frequent_values_for_known_segment(): void
    {
        $this->bindAccess(true);
        $values = $this->createMock(SegmentValueRepository::class);
        $values->expects($this->once())->method('supports')->with('browserCode')->willReturn(true);
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

    public function test_hides_restricted_segments_from_anonymous_users(): void
    {
        $this->bindAccess(true, 'anonymous');
        $values = $this->createMock(SegmentValueRepository::class);
        $values->expects($this->never())->method('supports');
        $values->expects($this->never())->method('mostFrequent');
        $this->app->instance(SegmentValueRepository::class, $values);

        $this->get($this->url('userId'))->assertBadRequest();
    }

    public function test_rejects_known_segments_that_need_unported_suggestion_logic(): void
    {
        $this->bindAccess(true);
        $values = $this->createMock(SegmentValueRepository::class);
        $values->expects($this->once())->method('supports')->with('pageTitle')->willReturn(false);
        $values->expects($this->never())->method('mostFrequent');
        $this->app->instance(SegmentValueRepository::class, $values);

        $this->get($this->url('pageTitle'))
            ->assertStatus(501)
            ->assertJsonPath('message', "Suggested values for the segment 'pageTitle' have not moved to Laravel yet.");
    }

    public function test_returns_no_values_when_suggestions_are_disabled(): void
    {
        $policy = $this->createStub(SegmentSuggestionPolicy::class);
        $policy->method('enabled')->willReturn(false);
        $this->app->instance(SegmentSuggestionPolicy::class, $policy);
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('browserCode'))->assertOk()->assertExactJson([]);
    }

    public function test_rejects_invalid_site_id(): void
    {
        $this->bindAccess(true);

        $this->get(str_replace('idSite=7', 'idSite=0', $this->url('browserCode')))->assertBadRequest();
    }

    public function test_reports_all_sites_as_an_unported_variant(): void
    {
        $this->bindAccess(true);

        $this->get(str_replace('idSite=7', 'idSite=all', $this->url('pageTitle')))
            ->assertStatus(501)
            ->assertJsonPath('message', 'All-sites segment suggestions have not moved to Laravel yet.');
    }

    private function bindAccess(bool $view, string $login = 'alice'): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn($view);
        $authorizer->method('hasSomeViewAccess')->willReturn($view);
        $authorizer->method('authenticatedLogin')->willReturn($login);
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
