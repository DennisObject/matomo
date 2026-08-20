<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\MultiSites\Events\MultiSitesFiltering;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Tests\TestCase;

class MultiSitesApiTest extends TestCase
{
    public function test_get_all_returns_visible_sites_with_evolution_and_filters_empty_rows(): void
    {
        $this->bindAccess([1, 2]);
        $this->bindPlugins(['Actions', 'Goals']);
        $this->bindCurrencies();
        $this->bindSites([
            $this->site(1, 'Shop', true),
            $this->site(2, 'Empty'),
        ]);
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [1, 2],
                $this->callback(static fn (array $periods): bool => count($periods) === 2
                    && $periods[0]->rangeKey() === '2026-08-14,2026-08-14'
                    && $periods[1]->rangeKey() === '2026-08-13,2026-08-13'),
                '',
                [
                    'nb_visits',
                    'nb_actions',
                    'Actions_nb_pageviews',
                    'Actions_hits',
                    'Goal_revenue',
                ],
                'MultiSites',
            )->willReturn([
                1 => [
                    '2026-08-14,2026-08-14' => [
                        'nb_visits' => 6,
                        'nb_actions' => 12,
                        'Actions_nb_pageviews' => 9,
                        'Actions_hits' => 13,
                        'Goal_revenue' => 50,
                    ],
                    '2026-08-13,2026-08-13' => [
                        'nb_visits' => 3,
                        'nb_actions' => 8,
                        'Actions_nb_pageviews' => 6,
                        'Actions_hits' => 9,
                        'Goal_revenue' => 40,
                    ],
                ],
            ]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('getAll'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'Shop')
            ->assertJsonPath('0.nb_visits', 6)
            ->assertJsonPath('0.visits_evolution', '100%')
            ->assertJsonPath('0.visits_evolution_trend', 1)
            ->assertJsonPath('0.previous_nb_visits', 3)
            ->assertJsonPath('0.revenue_evolution', '25%')
            ->assertJsonPath('0.idsite', 1)
            ->assertJsonPath('0.main_url', 'https://shop.test');
    }

    public function test_get_all_honors_show_columns_and_keeps_zero_rows_without_visits(): void
    {
        $this->bindAccess([2]);
        $this->bindPlugins(['Actions']);
        $this->bindCurrencies();
        $this->bindSites([$this->site(2, 'Search only')]);
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [2],
                $this->isType('array'),
                '',
                ['Actions_hits'],
                'MultiSites',
            )->willReturn([]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('getAll', ['showColumns' => 'hits']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.hits', 0)
            ->assertJsonMissingPath('0.nb_visits');
    }

    public function test_get_one_returns_one_zero_row_and_checks_site_access(): void
    {
        $this->bindAccess([7]);
        $this->bindPlugins([]);
        $this->bindCurrencies();
        $this->bindSites([$this->site(7, 'Quiet site')]);
        $this->app->instance(NumericArchiveRepository::class, $this->createStub(NumericArchiveRepository::class));

        $this->get($this->url('getOne', ['idSite' => '7']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.nb_visits', 0)
            ->assertJsonPath('0.idsite', 7)
            ->assertJsonMissingPath('0.label');
    }

    public function test_get_one_rejects_a_site_without_view_access(): void
    {
        $this->bindAccess([]);

        $this->get($this->url('getOne', ['idSite' => '7']))
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                "You can't access this resource as it requires 'view' access for the website id = 7.",
            );
    }

    public function test_month_evolution_uses_the_complete_previous_calendar_month(): void
    {
        $this->bindAccess([7]);
        $this->bindPlugins([]);
        $this->bindCurrencies();
        $this->bindSites([$this->site(7, 'Monthly')]);
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->callback(static fn (array $periods): bool => count($periods) === 2
                    && $periods[0]->rangeKey() === '2024-02-01,2024-02-29'
                    && $periods[1]->rangeKey() === '2024-01-01,2024-01-31'),
                '',
                ['nb_visits', 'nb_actions'],
                'MultiSites',
            )->willReturn([]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('getOne', [
            'idSite' => '7',
            'period' => 'month',
            'date' => '2024-02-20',
        ]))->assertOk()->assertJsonPath('0.nb_visits', 0);
    }

    public function test_get_all_with_groups_builds_totals_groups_and_a_flat_page(): void
    {
        $this->bindAccess([1, 2, 3]);
        $this->bindPlugins(['Actions', 'Goals']);
        $this->bindCurrencies();
        $this->bindSites([
            $this->site(1, 'Alpha', true, 'Stores'),
            $this->site(2, 'Beta', false, 'Stores'),
            $this->site(3, 'Docs'),
        ]);
        $archives = $this->createStub(NumericArchiveRepository::class);
        $archives->method('pluginMetrics')->willReturn([
            1 => [
                '2026-08-14,2026-08-14' => [
                    'nb_visits' => 5,
                    'nb_actions' => 8,
                    'Actions_nb_pageviews' => 6,
                    'Actions_hits' => 8,
                    'Goal_revenue' => 25,
                    'Goal_nb_conversions' => 2,
                    'Goal_0_nb_conversions' => 1,
                    'Goal_0_revenue' => 20,
                ],
            ],
            2 => ['2026-08-14,2026-08-14' => ['nb_visits' => 3, 'nb_actions' => 4]],
            3 => ['2026-08-14,2026-08-14' => ['nb_visits' => 1, 'nb_actions' => 2]],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('getAllWithGroups', [
            'filter_limit' => '3',
            'format_metrics' => '0',
        ]))->assertOk()
            ->assertJsonPath('numSites', 4)
            ->assertJsonPath('totals.nb_visits', 9)
            ->assertJsonPath('totals.nb_actions', 14)
            ->assertJsonPath('lastDate', '2026-08-13')
            ->assertJsonPath('sites.0.label', 'Stores')
            ->assertJsonPath('sites.0.isGroup', 1)
            ->assertJsonPath('sites.0.nb_visits', 8)
            ->assertJsonPath('sites.1.label', 'Alpha')
            ->assertJsonPath('sites.1.orders', 1)
            ->assertJsonPath('sites.2.label', 'Beta')
            ->assertJsonMissingPath('sites.2.orders');
    }

    public function test_group_search_keeps_totals_but_updates_the_site_count(): void
    {
        $this->bindAccess([1, 2]);
        $this->bindPlugins([]);
        $this->bindCurrencies();
        $this->bindSites([
            $this->site(1, 'Alpha', false, 'Stores'),
            $this->site(2, 'Docs'),
        ]);
        $archives = $this->createStub(NumericArchiveRepository::class);
        $archives->method('pluginMetrics')->willReturn([
            1 => ['2026-08-14,2026-08-14' => ['nb_visits' => 5]],
            2 => ['2026-08-14,2026-08-14' => ['nb_visits' => 2]],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('getAllWithGroups', [
            'pattern' => 'docs',
            'filter_limit' => '10',
            'format_metrics' => '0',
        ]))->assertOk()
            ->assertJsonPath('numSites', 1)
            ->assertJsonPath('totals.nb_visits', 7)
            ->assertJsonCount(1, 'sites')
            ->assertJsonPath('sites.0.label', 'Docs');
    }

    public function test_site_extension_cannot_add_an_unauthorized_site(): void
    {
        $this->bindAccess([1, 2]);
        $this->bindPlugins([]);
        $this->bindCurrencies();
        $this->bindSites([
            $this->site(1, 'One'),
            $this->site(2, 'Two'),
            $this->site(99, 'Private'),
        ]);
        $this->app->make(Dispatcher::class)->listen(
            MultiSitesFiltering::class,
            static function (MultiSitesFiltering $event): void {
                $event->siteIds = [2, 99];
            },
        );
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with([2], $this->isType('array'), '', ['nb_visits', 'nb_actions'], 'MultiSites')
            ->willReturn([2 => ['2026-08-14,2026-08-14' => ['nb_visits' => 1]]]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('getAll'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.idsite', 2);
    }

    public function test_grouped_report_rejects_multiple_periods(): void
    {
        $this->bindAccess([1]);

        $this->get($this->url('getAllWithGroups', [
            'date' => '2026-08-13,2026-08-14',
            'filter_limit' => '10',
        ]))->assertBadRequest()
            ->assertJsonPath('message', 'Multiple periods are not supported');
    }

    public function test_get_one_renders_multiple_dates_as_rss(): void
    {
        $this->bindAccess([1]);
        $this->bindPlugins([]);
        $this->bindCurrencies();
        $this->bindSites([$this->site(1, 'Example & Co')]);
        $archives = $this->createStub(NumericArchiveRepository::class);
        $archives->method('pluginMetrics')->willReturn([
            1 => [
                '2026-08-13,2026-08-13' => ['nb_visits' => 2],
                '2026-08-14,2026-08-14' => ['nb_visits' => 3],
            ],
        ]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $response = $this->get($this->url('getOne', [
            'idSite' => '1',
            'date' => '2026-08-13,2026-08-14',
            'format' => 'rss',
        ]))->assertOk()->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('<rss version="2.0">', $content);
        $this->assertStringContainsString('<title>Example &amp; Co on 2026-08-14</title>', $content);
        $this->assertStringContainsString('&lt;td&gt;3&lt;/td&gt;', $content);
    }

    /** @param list<int> $siteIds */
    private function bindAccess(array $siteIds): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('hasSomeViewAccess')->willReturn($siteIds !== []);
        $authorizer->method('hasViewAccessToSite')->willReturnCallback(
            static fn (mixed $authentication, int $siteId): bool => in_array($siteId, $siteIds, true),
        );
        $authorizer->method('siteIdsWithAtLeastViewAccess')->willReturn($siteIds);
        $authorizer->method('authenticatedLogin')->willReturn('alice');
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    /** @param list<string> $activePlugins */
    private function bindPlugins(array $activePlugins): void
    {
        $plugins = $this->createStub(PluginState::class);
        $plugins->method('isActivated')->willReturnCallback(
            static fn (string $plugin): bool => in_array($plugin, $activePlugins, true),
        );
        $this->app->instance(PluginState::class, $plugins);
    }

    private function bindCurrencies(): void
    {
        $currencies = $this->createStub(CurrencyProvider::class);
        $currencies->method('symbols')->willReturn(['USD' => '$']);
        $this->app->instance(CurrencyProvider::class, $currencies);
    }

    /** @param list<array<string, int|string|null>> $details */
    private function bindSites(array $details): void
    {
        $byId = [];

        foreach ($details as $site) {
            $byId[(int) $site['idsite']] = $site;
        }

        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturnCallback(
            static fn (int $siteId): array => $byId[$siteId] ?? [],
        );
        $sites->method('detailsForIds')->willReturnCallback(
            static function (array $siteIds, ?string $pattern = null, ?int $limit = null) use ($byId): array {
                $rows = array_values(array_intersect_key($byId, array_flip($siteIds)));

                if ($pattern !== null && $pattern !== '') {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $site): bool => str_contains(
                            strtolower((string) $site['name']),
                            strtolower($pattern),
                        ),
                    ));
                }

                return $limit === null ? $rows : array_slice($rows, 0, $limit);
            },
        );
        $sites->method('timezone')->willReturn('UTC');
        $this->app->instance(SiteRepository::class, $sites);
    }

    /** @return array<string, int|string|null> */
    private function site(int $siteId, string $name, bool $ecommerce = false, string $group = ''): array
    {
        return [
            'idsite' => $siteId,
            'name' => $name,
            'main_url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'group' => $group,
            'ecommerce' => (int) $ecommerce,
            'currency' => 'USD',
            'timezone' => 'UTC',
        ];
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'MultiSites.'.$method,
            'period' => 'day',
            'date' => '2026-08-14',
            'format' => 'json',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
    }
}
