<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActionsApiTest extends TestCase
{
    public function test_returns_the_actions_overview_and_requested_processed_metric(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $numbers = $this->createMock(NumericArchiveRepository::class);
        $numbers->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->isType('array'),
                '',
                ['Actions_sum_time_generation', 'Actions_nb_hits_with_time_generation'],
                'Actions',
            )->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'Actions_sum_time_generation' => 12,
                'Actions_nb_hits_with_time_generation' => 5,
            ]]]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url('get', ['columns' => 'avg_time_generation']))
            ->assertOk()
            ->assertExactJson(['avg_time_generation' => 2.4]);
    }

    #[DataProvider('tableMethodProvider')]
    public function test_routes_each_actions_table_method(string $method, string $record): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('records')
            ->with([7], $this->isType('array'), '', $record, false)
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $record => [$this->row('Label', [
                    'nb_hits_following_search' => 1,
                    'entry_nb_visits' => 1,
                    'exit_nb_visits' => 1,
                    'site_search_has_no_result' => 1,
                    'nb_actions' => 2,
                ])],
            ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
        $this->app->instance(NumericArchiveRepository::class, $this->createStub(NumericArchiveRepository::class));

        $this->get($this->url($method))
            ->assertOk()
            ->assertJsonPath('0.label', 'Label');
    }

    /** @return iterable<string, array{string, string}> */
    public static function tableMethodProvider(): iterable
    {
        yield 'page URLs' => ['getPageUrls', 'Actions_actions_url'];
        yield 'page URLs after search' => ['getPageUrlsFollowingSiteSearch', 'Actions_actions_url'];
        yield 'page titles after search' => ['getPageTitlesFollowingSiteSearch', 'Actions_actions'];
        yield 'entry page URLs' => ['getEntryPageUrls', 'Actions_actions_url'];
        yield 'exit page URLs' => ['getExitPageUrls', 'Actions_actions_url'];
        yield 'page titles' => ['getPageTitles', 'Actions_actions'];
        yield 'entry page titles' => ['getEntryPageTitles', 'Actions_actions'];
        yield 'exit page titles' => ['getExitPageTitles', 'Actions_actions'];
        yield 'downloads' => ['getDownloads', 'Actions_downloads'];
        yield 'outlinks' => ['getOutlinks', 'Actions_outlink'];
        yield 'search keywords' => ['getSiteSearchKeywords', 'Actions_sitesearch'];
        yield 'search keywords without results' => ['getSiteSearchNoResultKeywords', 'Actions_sitesearch'];
        yield 'search categories' => ['getSiteSearchCategories', 'Actions_SiteSearchCategories'];
    }

    public function test_requires_and_validates_method_specific_parameters(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        foreach ([
            'getPageUrl' => 'pageUrl',
            'getPageTitle' => 'pageName',
            'getDownload' => 'downloadUrl',
            'getOutlink' => 'outlinkUrl',
        ] as $method => $parameter) {
            $this->get($this->url($method))
                ->assertBadRequest()
                ->assertJsonPath('message', "Please specify a value for '{$parameter}'.");
        }

        $this->get($this->url('getPageUrls', ['depth' => '-1']))
            ->assertBadRequest()
            ->assertJsonPath('message', 'The API parameter [depth] must be a scalar value.');
    }

    #[DataProvider('singularMethodProvider')]
    public function test_finds_a_single_action_in_its_archive_tree(
        string $method,
        string $parameter,
        string $value,
        string $record,
        string $parent,
        string $leaf,
    ): void {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            $record => [$this->row($parent, [], 42)],
            $record.'_42' => [$this->row($leaf, [], null, ['url' => $value])],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
        $this->app->instance(NumericArchiveRepository::class, $this->createStub(NumericArchiveRepository::class));

        $this->get($this->url($method, [$parameter => $value]))
            ->assertOk()
            ->assertJsonPath('0.label', $leaf);
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function singularMethodProvider(): iterable
    {
        yield 'page URL' => [
            'getPageUrl',
            'pageUrl',
            'https://example.test/docs/guide',
            'Actions_actions_url',
            'docs',
            '/guide',
        ];
        yield 'download' => [
            'getDownload',
            'downloadUrl',
            'https://example.test/file.zip',
            'Actions_downloads',
            'example.test',
            '/file.zip',
        ];
        yield 'outlink' => [
            'getOutlink',
            'outlinkUrl',
            'https://outside.test/path',
            'Actions_outlink',
            'outside.test',
            '/path',
        ];
    }

    public function test_finds_a_single_page_title(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Actions_actions' => [$this->row(' Guide', [], null, ['page_title_path' => 'Guide'])],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
        $this->app->instance(NumericArchiveRepository::class, $this->createStub(NumericArchiveRepository::class));

        $this->get($this->url('getPageTitle', ['pageName' => 'Guide']))
            ->assertOk()
            ->assertJsonPath('0.label', ' Guide');
    }

    public function test_expands_pages_and_adds_processed_goal_and_segment_data(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Actions_actions_url' => [$this->row('docs', [
                'nb_visits' => 4,
                'nb_hits' => 5,
                'entry_nb_visits' => 2,
                'entry_bounce_count' => 1,
                'exit_nb_visits' => 2,
            ], 42)],
            'Actions_actions_url_42' => [$this->row('/guide', [
                'nb_visits' => 3,
                'nb_hits' => 4,
                'sum_time_spent' => 21,
                'sum_time_generation' => 3,
                'nb_hits_with_time_generation' => 2,
                'entry_nb_visits' => 2,
                'entry_bounce_count' => 1,
                'exit_nb_visits' => 1,
                'goal_2_nb_conversions_page_uniq' => 3,
                'goal_2_nb_conversions_attrib' => 2,
            ], null, ['url' => 'https://example.test/docs/guide'])],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
        $numbers = $this->createStub(NumericArchiveRepository::class);
        $numbers->method('pluginMetrics')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Goal_2_nb_conversions' => 2,
        ]]]);
        $this->app->instance(NumericArchiveRepository::class, $numbers);

        $this->get($this->url('getPageUrls', ['expanded' => '1']))
            ->assertOk()
            ->assertJsonPath('0.segment', 'pageUrl=^https%253A%252F%252Fexample.test%252Fdocs')
            ->assertJsonPath('0.idsubdatatable', 42)
            ->assertJsonPath('0.subtable.0.segment', 'pageUrl==https%253A%252F%252Fexample.test%252Fdocs%252Fguide')
            ->assertJsonPath('0.subtable.0.avg_time_on_page', 5)
            ->assertJsonPath('0.subtable.0.avg_time_generation', 1.5)
            ->assertJsonPath('0.subtable.0.bounce_rate', '50%')
            ->assertJsonPath('0.subtable.0.exit_rate', '33%')
            ->assertJsonPath('0.subtable.0.nb_hits_percent_of_total', '80%')
            ->assertJsonPath('0.subtable.0.goals.2.nb_conversions_page_rate', 1);
    }

    public function test_flattens_urls_and_removes_the_default_action_name(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Actions_actions_url' => [$this->row('docs', ['nb_visits' => 2], 42)],
            'Actions_actions_url_42' => [$this->row('/index', ['nb_visits' => 2])],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
        $this->app->instance(NumericArchiveRepository::class, $this->createStub(NumericArchiveRepository::class));

        $this->get($this->url('getPageUrls', ['flat' => '1', 'show_dimensions' => '1']))
            ->assertOk()
            ->assertJsonPath('0.label', '/docs/')
            ->assertJsonPath('0.Actions_PageUrl', '/docs/');
    }

    public function test_filters_and_processes_site_search_reports(): void
    {
        $this->bindViewAccess(2);
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'Actions_sitesearch' => [
                $this->row('found', [
                    'nb_visits' => 2,
                    'nb_hits' => 5,
                    'site_search_has_no_result' => 0,
                ]),
                $this->row('missing', [
                    'nb_visits' => 1,
                    'nb_hits' => 1,
                    'site_search_has_no_result' => 1,
                ]),
                $this->row('-1', ['site_search_has_no_result' => 1]),
            ],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);
        $this->app->instance(NumericArchiveRepository::class, $this->createStub(NumericArchiveRepository::class));

        $this->get($this->url('getSiteSearchKeywords'))
            ->assertOk()
            ->assertJsonPath('0.nb_pages_per_search', 2.5)
            ->assertJsonMissingPath('0.site_search_has_no_result');

        $this->get($this->url('getSiteSearchNoResultKeywords'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.label', 'missing')
            ->assertJsonPath('0.nb_pages_per_search', 1);
    }

    /**
     * @param  array<string, float|int|string|null>  $metrics
     * @param  array<string, float|int|string|null>  $metadata
     * @return array{
     *     columns: array<string, float|int|string|null>,
     *     metadata: array<string, float|int|string|null>,
     *     subtableId: int|null
     * }
     */
    private function row(
        string $label,
        array $metrics = [],
        ?int $subtableId = null,
        array $metadata = [],
    ): array {
        return [
            'columns' => ['label' => $label, 'nb_visits' => 1, 'nb_hits' => 1, ...$metrics],
            'metadata' => $metadata,
            'subtableId' => $subtableId,
        ];
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'Actions.'.$method,
            'idSite' => '7',
            'period' => 'day',
            'date' => '2026-08-14',
            'format' => 'json',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
    }

    private function bindSite(): void
    {
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $sites->method('urls')->willReturn(['https://example.test/']);
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function bindViewAccess(int $calls = 1): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->exactly($calls))
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
