<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Transitions\TransitionsPeriodPolicy;
use Tests\TestCase;

class ClientTranslationsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ApiAccessAuthorizer::class, $this->createStub(ApiAccessAuthorizer::class));
    }

    public function test_returns_overlay_client_translations(): void
    {
        $this->get($this->url('Overlay.getTranslations'))
            ->assertOk()
            ->assertExactJson([
                'oneClick' => '1 click',
                'clicks' => '%s clicks',
                'clicksFromXLinks' => '%1$s clicks from one of %2$s links',
                'link' => 'Link',
            ]);
    }

    public function test_returns_transition_client_translations(): void
    {
        $response = $this->get($this->url('Transitions.getTranslations'))
            ->assertOk()
            ->json();

        $this->assertIsArray($response);
        $this->assertCount(39, $response);
        $this->assertSame('%s pageviews', $response['pageviewsInline'] ?? null);
        $this->assertSame('From AI assistants', $response['fromAIAssistants'] ?? null);
        $this->assertSame('%s direct entries', $response['directEntriesInline'] ?? null);
        $this->assertSame('Date range:', $response['DateRange'] ?? null);
    }

    public function test_returns_whether_a_transition_period_is_allowed(): void
    {
        $periods = $this->createMock(TransitionsPeriodPolicy::class);
        $periods->expects($this->once())
            ->method('isAllowed')
            ->with(7, 'range', '2026-08-01,2026-08-07')
            ->willReturn(false);
        $this->app->instance(TransitionsPeriodPolicy::class, $periods);

        $this->get($this->url('Transitions.isPeriodAllowed').
            '&idSite=7&period=range&date=2026-08-01%2C2026-08-07')
            ->assertOk()
            ->assertExactJson(['value' => false]);
    }

    private function url(string $method): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => $method,
            'format' => 'json',
        ]);
    }
}
