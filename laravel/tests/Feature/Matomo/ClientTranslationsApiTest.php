<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
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

    private function url(string $method): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => $method,
            'format' => 'json',
        ]);
    }
}
