<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\BotTracking\Events\AiAssistantDefinitionsCollecting;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BotTrackingArchiveApiTest extends TestCase
{
    public function test_returns_overview_metrics_and_click_through_rate(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->isType('array'),
                '',
                [
                    'BotTracking_AIChatbotsRequests',
                    'BotTracking_AIChatbotsAcquiredVisits',
                    'BotTracking_AIChatbotsUniquePageUrls',
                    'BotTracking_AIChatbotsNotFoundRequests',
                    'BotTracking_AIChatbotsUniqueChatbots',
                    'BotTracking_AIChatbotsUniqueDocumentUrls',
                    'BotTracking_AIChatbotsServerErrorRequests',
                ],
                'BotTracking',
            )
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'BotTracking_AIChatbotsRequests' => 10,
                'BotTracking_AIChatbotsAcquiredVisits' => 1,
                'BotTracking_AIChatbotsUniquePageUrls' => 3,
                'BotTracking_AIChatbotsNotFoundRequests' => 1,
                'BotTracking_AIChatbotsUniqueChatbots' => 5,
                'BotTracking_AIChatbotsUniqueDocumentUrls' => 3,
                'BotTracking_AIChatbotsServerErrorRequests' => 2,
            ]]]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('get'))
            ->assertOk()
            ->assertExactJson([
                'BotTracking_AIChatbotsRequests' => 10,
                'BotTracking_AIChatbotsAcquiredVisits' => 1,
                'BotTracking_AIChatbotsUniquePageUrls' => 3,
                'BotTracking_AIChatbotsNotFoundRequests' => 1,
                'BotTracking_AIChatbotsUniqueChatbots' => 5,
                'BotTracking_AIChatbotsUniqueDocumentUrls' => 3,
                'BotTracking_AIChatbotsServerErrorRequests' => 2,
                'BotTracking_AIChatbotsClickThroughRate' => 0.1,
            ]);
    }

    public function test_fetches_processed_metric_dependencies_and_ignores_segment(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(NumericArchiveRepository::class);
        $archives->expects($this->once())
            ->method('pluginMetrics')
            ->with(
                [7],
                $this->isType('array'),
                '',
                [
                    'BotTracking_AIChatbotsRequests',
                    'BotTracking_AIChatbotsAcquiredVisits',
                ],
                'BotTracking',
            )
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'BotTracking_AIChatbotsRequests' => 8,
                'BotTracking_AIChatbotsAcquiredVisits' => 3,
            ]]]);
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('get', [
            'columns' => 'BotTracking_AIChatbotsClickThroughRate',
            'segment' => 'browserCode==FF',
        ]))->assertOk()->assertExactJson([
            'BotTracking_AIChatbotsClickThroughRate' => 0.375,
        ]);
    }

    public function test_omits_day_only_metrics_for_week_period(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $requestedMetrics = [];
        $archives = $this->createStub(NumericArchiveRepository::class);
        $archives->method('pluginMetrics')->willReturnCallback(
            function (array $siteIds, array $periods, string $segment, array $metrics) use (&$requestedMetrics): array {
                $requestedMetrics = $metrics;

                return [];
            },
        );
        $this->app->instance(NumericArchiveRepository::class, $archives);

        $this->get($this->url('get', ['period' => 'week']))->assertOk();

        $this->assertNotContains('BotTracking_AIChatbotsUniquePageUrls', $requestedMetrics);
        $this->assertNotContains('BotTracking_AIChatbotsUniqueDocumentUrls', $requestedMetrics);
    }

    public function test_expands_chatbot_page_rows_and_adds_metadata(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('records')
            ->with([7], $this->isType('array'), '', 'BotTracking_AIChatbotsPages', true)
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                'BotTracking_AIChatbotsPages' => [
                    $this->row('ChatGPT-User', ['requests' => 2, 'page_requests' => 2], 42),
                    $this->row('Claude-User', ['requests' => 1]),
                ],
                'BotTracking_AIChatbotsPages_42' => [
                    $this->row('example.test/article', ['requests' => 2]),
                ],
            ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIChatbotRequests', ['expanded' => '1']))
            ->assertOk()
            ->assertExactJson([
                [
                    'label' => 'ChatGPT',
                    'requests' => 2,
                    'page_requests' => 2,
                    'url' => 'chatgpt.com',
                    'logo' => 'plugins/Morpheus/icons/dist/aiAssistants/chatgpt.com.png',
                    'idsubdatatable' => 42,
                    'subtable' => [[
                        'label' => 'example.test/article',
                        'requests' => 2,
                    ]],
                ],
                [
                    'label' => 'Claude',
                    'requests' => 1,
                    'url' => 'claude.ai',
                    'logo' => 'plugins/Morpheus/icons/dist/aiAssistants/claude.ai.png',
                ],
            ]);
    }

    public function test_flattens_only_chatbots_with_page_subtables(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'BotTracking_AIChatbotsPages' => [
                $this->row('ChatGPT-User', ['requests' => 2], 42),
                $this->row('Claude-User', ['requests' => 1]),
            ],
            'BotTracking_AIChatbotsPages_42' => [
                $this->row('example.test/article', ['requests' => 2]),
            ],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIChatbotRequests', ['flat' => '1']))
            ->assertOk()
            ->assertExactJson([[
                'label' => 'ChatGPT - example.test/article',
                'requests' => 2,
                'BotTracking_AIChatbotName' => 'ChatGPT',
                'BotTracking_PageUrl' => 'example.test/article',
                'url' => null,
                'logo' => 'plugins/Morpheus/icons/dist/aiAssistants/xx.png',
            ]]);
    }

    public function test_plugins_can_extend_ai_assistant_metadata(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $this->app->make(Dispatcher::class)->listen(
            AiAssistantDefinitionsCollecting::class,
            static function (AiAssistantDefinitionsCollecting $event): void {
                $event->definitions['assistant.example'] = 'Example Assistant';
            },
        );
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            'BotTracking_AIChatbotsPages' => [
                $this->row('Example Assistant', ['requests' => 2]),
            ],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIChatbotRequests'))
            ->assertOk()
            ->assertJsonPath('0.url', 'assistant.example')
            ->assertJsonPath(
                '0.logo',
                'plugins/Morpheus/icons/dist/aiAssistants/assistant.example.png',
            );
    }

    public function test_uses_document_archive_for_secondary_dimension(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('records')
            ->with([7], $this->isType('array'), '', 'BotTracking_AIChatbotsDocuments', true)
            ->willReturn([]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIChatbotRequests', [
            'secondaryDimension' => 'documents',
            'expanded' => '1',
        ]))->assertOk()->assertExactJson([]);
    }

    public function test_rejects_invalid_secondary_dimension(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getAIChatbotRequests', ['secondaryDimension' => 'links']))
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Secondary dimension 'links' is not valid for the API getAIChatbotRequests. ".
                    'Use one of: pages, documents',
            ]);
    }

    #[DataProvider('subtableMethodProvider')]
    public function test_reads_chatbot_url_subtables(string $method, string $record): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('records')
            ->with([7], $this->isType('array'), '', $record.'_42', false)
            ->willReturn([7 => ['2026-08-14,2026-08-14' => [
                $record.'_42' => [$this->row('example.test/item', ['requests' => 3])],
            ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url($method, ['idSubtable' => '42']))
            ->assertOk()
            ->assertExactJson([[
                'label' => 'example.test/item',
                'requests' => 3,
            ]]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function subtableMethodProvider(): iterable
    {
        yield 'page URLs' => ['getPageUrlsForAIChatbot', 'BotTracking_AIChatbotsPages'];
        yield 'document URLs' => ['getDocumentUrlsForAIChatbot', 'BotTracking_AIChatbotsDocuments'];
    }

    public function test_requires_subtable_id(): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getPageUrlsForAIChatbot'))
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => "Please specify a value for 'idSubtable'.",
            ]);
    }

    #[DataProvider('flatReportProvider')]
    public function test_routes_flat_reports_to_their_archive(string $method, string $record): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $archives = $this->createMock(HierarchicalBlobArchiveRepository::class);
        $archives->expects($this->once())
            ->method('records')
            ->with([7], $this->isType('array'), '', $record, false)
            ->willReturn([]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url($method, ['segment' => 'browserCode==FF']))
            ->assertOk()
            ->assertExactJson([]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function flatReportProvider(): iterable
    {
        yield 'content pages' => [
            'getAIChatbotContentPages',
            'BotTracking_AIChatbotsRequestedPages',
        ];
        yield 'content documents' => [
            'getAIChatbotContentDocuments',
            'BotTracking_AIChatbotsRequestedDocuments',
        ];
        yield 'broken content' => [
            'getAIChatbotBrokenContent',
            'BotTracking_AIChatbotsBrokenContent',
        ];
        yield 'human favoured' => [
            'getAIChatbotHumanFavouredPages',
            'BotTracking_AIChatbotsHumanFavouredPages',
        ];
        yield 'AI favoured' => [
            'getAIChatbotAIFavouredPages',
            'BotTracking_AIChatbotsAIFavouredPages',
        ];
    }

    public function test_computes_content_averages_and_omits_missing_averages(): void
    {
        $this->bindViewAccess();
        $this->bindSite();
        $record = 'BotTracking_AIChatbotsRequestedPages';
        $archives = $this->createStub(HierarchicalBlobArchiveRepository::class);
        $archives->method('records')->willReturn([7 => ['2026-08-14,2026-08-14' => [
            $record => [
                $this->row('example.test/one', [
                    'requests' => 3,
                    'sum_server_time' => 7500,
                    'nb_server_time' => 3,
                    'sum_response_size' => 1500,
                    'nb_response_size' => 2,
                ]),
                $this->row('example.test/two', [
                    'requests' => 1,
                    'sum_server_time' => 0,
                    'nb_server_time' => 0,
                    'sum_response_size' => 0,
                    'nb_response_size' => 0,
                ]),
            ],
        ]]]);
        $this->app->instance(HierarchicalBlobArchiveRepository::class, $archives);

        $this->get($this->url('getAIChatbotContentPages'))
            ->assertOk()
            ->assertExactJson([
                [
                    'label' => 'example.test/one',
                    'requests' => 3,
                    'sum_server_time' => 7500,
                    'nb_server_time' => 3,
                    'sum_response_size' => 1500,
                    'nb_response_size' => 2,
                    'avg_server_time' => 2.5,
                    'avg_response_size' => 750,
                ],
                [
                    'label' => 'example.test/two',
                    'requests' => 1,
                    'sum_server_time' => 0,
                    'nb_server_time' => 0,
                    'sum_response_size' => 0,
                    'nb_response_size' => 0,
                ],
            ]);
    }

    public function test_denies_site_without_view_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('get'))
            ->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires 'view' access for the website id = 7.",
            ]);
    }

    /**
     * @param  array<string, float|int|string|null>  $metrics
     * @return array{columns: array<string, float|int|string|null>, metadata: array{}, subtableId: int|null}
     */
    private function row(string $label, array $metrics, ?int $subtableId = null): array
    {
        return [
            'columns' => ['label' => $label, ...$metrics],
            'metadata' => [],
            'subtableId' => $subtableId,
        ];
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'BotTracking.'.$method,
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
        $this->app->instance(SiteRepository::class, $sites);
    }

    private function bindViewAccess(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->with($this->isInstanceOf(ApiAuthentication::class), 7)
            ->willReturn(true);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }
}
