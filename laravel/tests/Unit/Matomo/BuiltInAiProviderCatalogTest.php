<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\AiProviders\AiProviderDefinition;
use App\Matomo\AiProviders\BuiltInAiProviderCatalog;
use App\Matomo\AiProviders\Events\AiProvidersCollecting;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class BuiltInAiProviderCatalogTest extends TestCase
{
    public function test_events_can_add_remove_and_restrict_providers_without_overwriting_builtins(): void
    {
        $events = new Dispatcher;
        $events->listen(AiProvidersCollecting::class, function (AiProvidersCollecting $providers): void {
            $this->assertFalse($providers->add($this->definition('openai')));
            $providers->setSelectable('anthropic', false);
            $providers->remove('google');
            $this->assertTrue($providers->add($this->definition('local')));
        });

        $catalog = new BuiltInAiProviderCatalog($events);

        $this->assertSame('OpenAI', $catalog->find('openai')?->name);
        $this->assertNull($catalog->find('google'));
        $this->assertFalse($catalog->isSelectable('anthropic'));
        $this->assertNotNull($catalog->find('anthropic'));
        $this->assertTrue($catalog->isSelectable('local'));
        $this->assertSame(
            ['openai', 'bedrock', 'custom-provider', 'local'],
            array_map(static fn (AiProviderDefinition $provider): string => $provider->id, $catalog->selectable()),
        );
    }

    private function definition(string $id): AiProviderDefinition
    {
        return new AiProviderDefinition(
            id: $id,
            name: ucfirst($id),
            description: 'Description',
            supportsCustomEndpoint: false,
            supportsFipsEndpoint: false,
            defaultEndpointUrl: 'https://provider.example/v1',
            endpointFieldTitle: 'Endpoint',
            endpointFieldPlaceholder: 'Endpoint',
            defaultModel: 'model',
        );
    }
}
