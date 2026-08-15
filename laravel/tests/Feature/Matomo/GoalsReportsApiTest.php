<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoalsReportsApiTest extends TestCase
{
    #[DataProvider('itemReports')]
    public function test_returns_ecommerce_item_reports(
        string $method,
        string $recordName,
        string $segment,
    ): void {
        $this->bindViewAccess();
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', $recordName)
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [[
                'columns' => [
                    'label' => 'Product A',
                    'revenue' => 150,
                    'quantity' => 4,
                    'price' => 300,
                    'orders' => 2,
                    'nb_visits' => 4,
                    'nb_actions' => 8,
                ],
                'metadata' => [],
            ]]]]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            "/index.php?module=API&method={$method}&idSite=7&period=day".
            '&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([[
            'label' => 'Product A',
            'revenue' => 150,
            'quantity' => 4,
            'orders' => 2,
            'nb_visits' => 4,
            'nb_actions' => 8,
            'nb_visits_percent_of_total' => '100%',
            'nb_actions_percent_of_total' => '100%',
            'revenue_percent_of_total' => '100%',
            'avg_price' => 150,
            'avg_quantity' => 2,
            'conversion_rate' => '50%',
            'segment' => $segment.'==Product+A',
        ]]);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function itemReports(): iterable
    {
        yield 'SKU' => ['Goals.getItemsSku', 'Goals_ItemsSku', 'productSku'];
        yield 'name' => ['Goals.getItemsName', 'Goals_ItemsName', 'productName'];
        yield 'category' => ['Goals.getItemsCategory', 'Goals_ItemsCategory', 'productCategory'];
    }

    public function test_returns_abandoned_carts_and_uses_view_price_when_no_cart_price_exists(): void
    {
        $this->bindViewAccess();
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', 'Goals_ItemsSku_Cart')
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [[
                'columns' => [
                    'label' => 'Value not defined',
                    'quantity' => 0,
                    'orders' => 0,
                    'avg_price_viewed' => 12.5,
                ],
                'metadata' => ['ignored' => 'yes'],
            ]]]]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            '/index.php?module=API&method=Goals.getItemsSku&idSite=7&period=day'.
            '&date=2026-08-14&abandonedCarts=1&showMetadata=0'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([[
            'label' => 'Product SKU not defined',
            'quantity' => 0,
            'abandoned_carts' => 0,
            'avg_price' => 12.5,
            'avg_quantity' => 0,
            'conversion_rate' => '0%',
        ]]);
    }

    #[DataProvider('rangeReports')]
    public function test_returns_sorted_goal_conversion_ranges(
        string $method,
        string $recordName,
        string $expectedLabel,
    ): void {
        $this->bindViewAccess();
        $archives = $this->createMock(BlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('rows')
            ->with([7], $this->isType('array'), '', $recordName)
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                ['columns' => ['label' => '9-14', 'nb_conversions' => 1], 'metadata' => []],
                ['columns' => ['label' => '1-1', 'nb_conversions' => 3], 'metadata' => []],
            ]]]);
        $this->app->instance(BlobArchiveRepository::class, $archives);

        $this->get(
            "/index.php?module=API&method={$method}&idSite=7&idGoal=2&period=day".
            '&date=2026-08-14&format=json&token_auth=view-token',
        )->assertOk()->assertJsonPath('0.label', $expectedLabel)
            ->assertJsonPath('0.nb_conversions_percent_of_total', '75%')
            ->assertJsonPath('1.nb_conversions_percent_of_total', '25%');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rangeReports(): iterable
    {
        yield 'days' => ['Goals.getDaysToConversion', 'Goal_2_days_until_conv', '1 day'];
        yield 'visits' => ['Goals.getVisitsUntilConversion', 'Goal_2_visits_until_conv', '1 visit'];
    }

    public function test_returns_all_new_and_returning_goal_metrics(): void
    {
        $this->bindViewAccess();
        $segments = $this->createMock(SegmentHashResolver::class);
        $segments->expects($this->exactly(3))->method('resolve')->willReturnMap([
            ['countryCode==NZ', 'all'],
            ['countryCode==NZ;visitorType==new', 'new'],
            ['countryCode==NZ;visitorType==returning,visitorType==returningCustomer', 'returning'],
        ]);
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->exactly(3))
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->isType('array'),
                $this->isType('string'),
                ['Goal_nb_conversions', 'Goal_nb_visits_converted', 'Goal_revenue', 'nb_visits'],
                'Goals',
            )
            ->willReturnCallback(static function (
                array $siteIds,
                array $periods,
                string $hash,
            ): array {
                $metrics = match ($hash) {
                    'all' => [
                        'Goal_nb_conversions' => 4,
                        'Goal_nb_visits_converted' => 3,
                        'Goal_revenue' => 20,
                        'nb_visits' => 6,
                    ],
                    'new' => [
                        'Goal_nb_conversions' => 3,
                        'Goal_nb_visits_converted' => 2,
                        'Goal_revenue' => 15,
                        'nb_visits' => 4,
                    ],
                    default => [
                        'Goal_nb_conversions' => 1,
                        'Goal_nb_visits_converted' => 1,
                        'Goal_revenue' => 5,
                        'nb_visits' => 2,
                    ],
                };

                return [7 => ['2026-08-14,2026-08-14' => $metrics]];
            });
        $this->app->instance(SegmentHashResolver::class, $segments);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get(
            '/index.php?module=API&method=Goals.get&idSite=7&period=day&date=2026-08-14'.
            '&segment=countryCode%3D%3DNZ&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            'nb_conversions' => 4,
            'nb_visits_converted' => 3,
            'revenue' => 20,
            'conversion_rate' => '50%',
            'nb_conversions_new_visit' => 3,
            'nb_visits_converted_new_visit' => 2,
            'revenue_new_visit' => 15,
            'conversion_rate_new_visit' => '50%',
            'nb_conversions_returning_visit' => 1,
            'nb_visits_converted_returning_visit' => 1,
            'revenue_returning_visit' => 5,
            'conversion_rate_returning_visit' => '50%',
        ]);
    }

    public function test_returns_ecommerce_metrics_without_formatting(): void
    {
        $this->bindViewAccess();
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->isType('array'),
                '',
                [
                    'Goal_0_nb_conversions',
                    'Goal_0_nb_visits_converted',
                    'Goal_0_revenue',
                    'Goal_0_revenue_subtotal',
                    'Goal_0_revenue_tax',
                    'Goal_0_revenue_shipping',
                    'Goal_0_revenue_discount',
                    'Goal_0_items',
                    'nb_visits',
                ],
                'Goals',
            )
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'Goal_0_nb_conversions' => 2,
                'Goal_0_nb_visits_converted' => 1,
                'Goal_0_revenue' => 50,
                'Goal_0_revenue_subtotal' => 40,
                'Goal_0_revenue_tax' => 5,
                'Goal_0_revenue_shipping' => 6,
                'Goal_0_revenue_discount' => 1,
                'Goal_0_items' => 3,
                'nb_visits' => 4,
            ]]]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get(
            '/index.php?module=API&method=Goals.getMetrics&idSite=7&period=day'.
            '&date=2026-08-14&idGoal=ecommerceOrder&format_metrics=0'.
            '&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson([
            'nb_conversions' => 2,
            'nb_visits_converted' => 1,
            'revenue' => 50,
            'revenue_subtotal' => 40,
            'revenue_tax' => 5,
            'revenue_shipping' => 6,
            'revenue_discount' => 1,
            'items' => 3,
            'conversion_rate' => 0.25,
            'avg_order_revenue' => 25,
        ]);
    }

    public function test_returns_requested_goal_specific_conversion_rate(): void
    {
        $this->bindViewAccess();
        $goals = $this->createMock(GoalRepository::class);
        $goals->expects($this->once())->method('activeForSites')->with([7])->willReturn([
            ['idgoal' => 1],
            ['idgoal' => 2],
        ]);
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->isType('array'),
                '',
                ['nb_visits', 'Goal_1_nb_visits_converted'],
                'Goals',
            )
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'Goal_1_nb_visits_converted' => 2,
                'nb_visits' => 3,
            ]]]);
        $this->app->instance(GoalRepository::class, $goals);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get(
            '/index.php?module=API&method=Goals.getMetrics&idSite=7&period=day'.
            '&date=2026-08-14&showAllGoalSpecificMetrics=1'.
            '&columns=goal_1_conversion_rate&format=json&token_auth=view-token',
        )->assertOk()->assertExactJson(['goal_1_conversion_rate' => '66.67%']);
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
    }
}
