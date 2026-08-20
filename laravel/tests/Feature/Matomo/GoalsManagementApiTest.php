<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Api\GoalDefinition;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use Tests\TestCase;

class GoalsManagementApiTest extends TestCase
{
    public function test_returns_one_active_goal_after_view_access_check(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasViewAccessToSite')->with(
            $this->anything(),
            7,
        )->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->once())->method('findActive')->with(7, 2)->willReturn([
            'idsite' => 7,
            'idgoal' => 2,
            'name' => 'Signup',
            'description' => '',
            'match_attribute' => 'manually',
            'revenue' => 0.0,
            'allow_multiple' => 0,
            'deleted' => 0,
            'event_value_as_revenue' => 0,
        ]);
        $this->app->instance(GoalRepository::class, $goals);

        $this->get(
            '/index.php?module=API&method=Goals.getGoal&idSite=7&idGoal=2'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            'idsite' => 7,
            'idgoal' => 2,
            'name' => 'Signup',
            'description' => '',
            'match_attribute' => 'manually',
            'revenue' => 0,
            'allow_multiple' => 0,
            'deleted' => 0,
            'event_value_as_revenue' => 0,
        ]);
    }

    public function test_returns_success_shape_when_goal_does_not_exist(): void
    {
        $this->allowViewAccess();

        $this->get(
            '/index.php?module=API&method=Goals.getGoal&idSite=7&idGoal=99'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_indexes_single_site_goals_and_orders_equal_names_by_descending_id(): void
    {
        $this->allowViewAccess();
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->once())->method('activeForSites')->with([7])->willReturn([
            $this->goalRecord(1, 'Beta'),
            $this->goalRecord(2, 'Alpha'),
            $this->goalRecord(3, 'Alpha'),
        ]);
        $this->app->instance(GoalRepository::class, $goals);

        $this->get(
            '/index.php?module=API&method=Goals.getGoals&idSite=7&orderByName=1'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            '3' => $this->goalRecord(3, 'Alpha'),
            '2' => $this->goalRecord(2, 'Alpha'),
            '1' => $this->goalRecord(1, 'Beta'),
        ]);
    }

    public function test_all_sites_uses_only_accessible_sites_and_returns_a_list(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->willReturn([2, 5]);
        $authorizer->expects($this->never())->method('hasViewAccessToSite');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->once())->method('activeForSites')->with([2, 5])->willReturn([
            [...$this->goalRecord(1, 'First'), 'idsite' => 2],
            [...$this->goalRecord(1, 'Second'), 'idsite' => 5],
        ]);
        $this->app->instance(GoalRepository::class, $goals);

        $this->get(
            '/index.php?module=API&method=Goals.getGoals&idSite=all'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            [...$this->goalRecord(1, 'First'), 'idsite' => 2],
            [...$this->goalRecord(1, 'Second'), 'idsite' => 5],
        ]);
    }

    public function test_adds_valid_goal_and_clears_site_tracker_cache(): void
    {
        $this->allowWriteAccess();
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->once())->method('create')->with(
            7,
            $this->callback(static fn (GoalDefinition $goal): bool => $goal->name === 'Checkout'
                && $goal->matchAttribute === 'url'
                && $goal->pattern === 'https://shop.example/thanks'
                && $goal->patternType === 'exact'
                && $goal->caseSensitive
                && $goal->revenue === 19.95
                && $goal->allowMultipleConversionsPerVisit
                && $goal->description === 'Paid order'
                && $goal->useEventValueAsRevenue),
        )->willReturn(4);
        $this->app->instance(GoalRepository::class, $goals);
        $cache = $this->createMock(SiteTrackerCacheInvalidator::class);
        $cache->expects($this->once())->method('clear')->with(7);
        $this->app->instance(SiteTrackerCacheInvalidator::class, $cache);

        $this->post(
            '/index.php?module=API&method=Goals.addGoal&format=json&token_auth=write-token',
            [
                'idSite' => '7',
                'name' => 'Checkout',
                'matchAttribute' => 'url',
                'pattern' => 'https://shop.example/thanks',
                'patternType' => 'EXACT',
                'caseSensitive' => '1',
                'revenue' => '19.95',
                'allowMultipleConversionsPerVisit' => '1',
                'description' => 'Paid order',
                'useEventValueAsRevenue' => '1',
            ],
        )->assertOk()->assertExactJson(['value' => 4]);
    }

    public function test_updates_event_goal_and_clears_site_tracker_cache(): void
    {
        $this->allowWriteAccess();
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->once())->method('update')->with(
            7,
            4,
            $this->callback(static fn (GoalDefinition $goal): bool => $goal->matchAttribute === 'event_name'
                && $goal->useEventValueAsRevenue),
        );
        $this->app->instance(GoalRepository::class, $goals);
        $cache = $this->createMock(SiteTrackerCacheInvalidator::class);
        $cache->expects($this->once())->method('clear')->with(7);
        $this->app->instance(SiteTrackerCacheInvalidator::class, $cache);

        $this->post(
            '/index.php?module=API&method=Goals.updateGoal&format=json&token_auth=write-token',
            [
                'idSite' => '7',
                'idGoal' => '4',
                'name' => 'Purchase',
                'matchAttribute' => 'event_name',
                'pattern' => 'order',
                'patternType' => 'contains',
                'useEventValueAsRevenue' => '1',
            ],
        )->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_rejects_event_revenue_on_non_event_update_without_writing(): void
    {
        $this->allowWriteAccess();
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->never())->method('update');
        $this->app->instance(GoalRepository::class, $goals);

        $this->post(
            '/index.php?module=API&method=Goals.updateGoal&format=json&token_auth=write-token',
            [
                'idSite' => '7',
                'idGoal' => '4',
                'name' => 'Visit',
                'matchAttribute' => 'title',
                'pattern' => 'Thank you',
                'patternType' => 'contains',
                'useEventValueAsRevenue' => '1',
            ],
        )->assertStatus(400)->assertJsonPath(
            'message',
            "'useEventValueAsRevenue' can only be 1 if the goal matches an event attribute.",
        );
    }

    public function test_deletes_goal_and_its_conversions_then_clears_cache(): void
    {
        $this->allowWriteAccess();
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->once())->method('delete')->with(7, 4);
        $this->app->instance(GoalRepository::class, $goals);
        $cache = $this->createMock(SiteTrackerCacheInvalidator::class);
        $cache->expects($this->once())->method('clear')->with(7);
        $this->app->instance(SiteTrackerCacheInvalidator::class, $cache);

        $this->post(
            '/index.php?module=API&method=Goals.deleteGoal&idSite=7&idGoal=4'.
            '&format=json&token_auth=write-token',
        )->assertOk()->assertExactJson(['result' => 'success', 'message' => 'ok']);
    }

    public function test_rejects_write_without_access_before_validation_or_storage(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->expects($this->once())
            ->method('siteIdsWithMinimumRole')
            ->with($this->anything(), SiteAccessRole::Write)
            ->willReturn([]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->never())->method('create');
        $this->app->instance(GoalRepository::class, $goals);

        $this->post(
            '/index.php?module=API&method=Goals.addGoal&format=json&token_auth=view-token',
            [
                'idSite' => '7',
                'name' => 'Goal',
                'matchAttribute' => 'url',
                'pattern' => '',
                'patternType' => 'invalid',
            ],
        )->assertStatus(401)->assertJsonPath(
            'message',
            "You can't access this resource as it requires 'write' access for the website id = 7.",
        );
    }

    public function test_validates_pattern_type_pattern_numeric_value_url_and_regex(): void
    {
        $this->allowWriteAccess();

        $cases = [
            ['url', 'value', 'invalid', 'The value "invalid" is not allowed, use one of: exact, contains, regex.'],
            ['url', '', 'contains', "Please specify a value for 'pattern'."],
            [
                'visit_duration',
                'slow',
                'greater_than',
                "Invalid pattern for match attribute 'visit_duration'. (got 'slow', expected numeric value).",
            ],
            [
                'url',
                'example.test',
                'exact',
                "If you choose 'exact match', the matching string must be a URL starting with http:// or https://. ".
                    "For example, 'http://www.yourwebsite.com/newsletter/subscribed.html'.",
            ],
            ['url', 'unclosed(', 'regex', 'The value "/unclosed(/" is not a valid regular expression.'],
        ];

        foreach ($cases as [$attribute, $pattern, $type, $message]) {
            $this->post(
                '/index.php?module=API&method=Goals.addGoal&format=json&token_auth=write-token',
                [
                    'idSite' => '7',
                    'name' => 'Goal',
                    'matchAttribute' => $attribute,
                    'pattern' => $pattern,
                    'patternType' => $type,
                ],
            )->assertStatus(400)->assertJsonPath('message', $message);
        }
    }

    private function allowViewAccess(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasViewAccessToSite')->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function allowWriteAccess(): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $authorizer->method('siteIdsWithMinimumRole')->willReturn([7]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    /** @return array<string, float|int|string> */
    private function goalRecord(int $goalId, string $name): array
    {
        return [
            'idsite' => 7,
            'idgoal' => $goalId,
            'name' => $name,
            'description' => '',
            'match_attribute' => 'url',
            'pattern' => 'https://example.test',
            'pattern_type' => 'exact',
            'case_sensitive' => 0,
            'allow_multiple' => 0,
            'revenue' => 0.0,
            'deleted' => 0,
            'event_value_as_revenue' => 0,
        ];
    }
}
