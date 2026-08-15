<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\BotTracking\BotTrackingRealtimeRepository;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BotTrackingRealtimeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_returns_decorated_chatbot_activity_for_default_window(): void
    {
        $this->bindViewAccess();
        $repository = $this->createMock(BotTrackingRealtimeRepository::class);
        $repository->expects($this->once())
            ->method('chatbotActivity')
            ->with([7], '2026-08-15 11:30:00', '2026-08-15 12:00:00')
            ->willReturn([[
                'label' => 'ChatGPT-User',
                'requests' => 2,
                'BotTracking_AIChatbotsUniquePageUrls' => 2,
                'BotTracking_AIChatbotsNotFoundRequests' => 1,
                'BotTracking_AIChatbotsServerErrorRequests' => 0,
            ]]);
        $this->app->instance(BotTrackingRealtimeRepository::class, $repository);

        $this->get($this->url('getAIChatbotsRealTime'))
            ->assertOk()
            ->assertExactJson([[
                'label' => 'ChatGPT',
                'requests' => 2,
                'BotTracking_AIChatbotsUniquePageUrls' => 2,
                'BotTracking_AIChatbotsNotFoundRequests' => 1,
                'BotTracking_AIChatbotsServerErrorRequests' => 0,
                'url' => 'chatgpt.com',
                'logo' => 'plugins/Morpheus/icons/dist/aiAssistants/chatgpt.com.png',
            ]]);
    }

    public function test_returns_linked_top_pages_for_requested_window(): void
    {
        $this->bindViewAccess();
        $repository = $this->createMock(BotTrackingRealtimeRepository::class);
        $repository->expects($this->once())
            ->method('topPageUrls')
            ->with([7], '2026-08-15 04:00:00', '2026-08-15 12:00:00')
            ->willReturn([[
                'label' => 'example.test/article',
                'requests' => 3,
            ]]);
        $this->app->instance(BotTrackingRealtimeRepository::class, $repository);

        $this->get($this->url('getTopPageUrlsRealTime', ['lastMinutes' => '480']))
            ->assertOk()
            ->assertExactJson([[
                'label' => 'example.test/article',
                'requests' => 3,
                'url' => 'https://example.test/article',
            ]]);
    }

    public function test_can_hide_realtime_metadata(): void
    {
        $this->bindViewAccess();
        $repository = $this->createStub(BotTrackingRealtimeRepository::class);
        $repository->method('chatbotActivity')->willReturn([[
            'label' => 'Claude-User',
            'requests' => 1,
            'BotTracking_AIChatbotsUniquePageUrls' => 0,
            'BotTracking_AIChatbotsNotFoundRequests' => 0,
            'BotTracking_AIChatbotsServerErrorRequests' => 1,
        ]]);
        $this->app->instance(BotTrackingRealtimeRepository::class, $repository);

        $this->get($this->url('getAIChatbotsRealTime', ['showMetadata' => '0']))
            ->assertOk()
            ->assertExactJson([[
                'label' => 'Claude',
                'requests' => 1,
                'BotTracking_AIChatbotsUniquePageUrls' => 0,
                'BotTracking_AIChatbotsNotFoundRequests' => 0,
                'BotTracking_AIChatbotsServerErrorRequests' => 1,
            ]]);
    }

    #[DataProvider('invalidLookbackProvider')]
    public function test_rejects_invalid_lookback(mixed $lastMinutes): void
    {
        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));

        $this->get($this->url('getAIChatbotsRealTime', ['lastMinutes' => $lastMinutes]))
            ->assertBadRequest()
            ->assertExactJson([
                'result' => 'error',
                'message' => 'lastMinutes only accepts values between 1 and 720',
            ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLookbackProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too large' => [721];
        yield 'not numeric' => ['hour'];
        yield 'array' => [['30']];
    }

    public function test_combines_all_viewable_sites(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('siteIdsWithAtLeastViewAccess')
            ->with($this->isInstanceOf(ApiAuthentication::class), null)
            ->willReturn([3, 7]);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
        $repository = $this->createMock(BotTrackingRealtimeRepository::class);
        $repository->expects($this->once())
            ->method('topPageUrls')
            ->with([3, 7], '2026-08-15 11:30:00', '2026-08-15 12:00:00')
            ->willReturn([]);
        $this->app->instance(BotTrackingRealtimeRepository::class, $repository);

        $this->get($this->url('getTopPageUrlsRealTime', ['idSite' => 'all']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_denies_site_without_view_access(): void
    {
        $authorizer = $this->createMock(ApiAccessAuthorizer::class);
        $authorizer->expects($this->once())
            ->method('hasViewAccessToSite')
            ->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);

        $this->get($this->url('getAIChatbotsRealTime'))
            ->assertUnauthorized()
            ->assertExactJson([
                'result' => 'error',
                'message' => "You can't access this resource as it requires 'view' access for the website id = 7.",
            ]);
    }

    public function test_renders_single_site_realtime_rss(): void
    {
        $this->bindViewAccess();
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('timezone')->willReturn('UTC');
        $sites->method('details')->willReturn(['name' => 'Example']);
        $this->app->instance(SiteRepository::class, $sites);
        $repository = $this->createStub(BotTrackingRealtimeRepository::class);
        $repository->method('topPageUrls')->willReturn([[
            'label' => 'example.test/article',
            'requests' => 3,
        ]]);
        $this->app->instance(BotTrackingRealtimeRepository::class, $repository);

        $this->get($this->url('getTopPageUrlsRealTime', ['format' => 'rss']))
            ->assertOk()
            ->assertHeader('content-type', 'text/xml; charset=utf-8')
            ->assertSee('<rss version="2.0">', false)
            ->assertSee('example.test/article');
    }

    /** @param array<string, mixed> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'BotTracking.'.$method,
            'idSite' => '7',
            'format' => 'json',
            'token_auth' => 'view-token',
            ...$parameters,
        ]);
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
