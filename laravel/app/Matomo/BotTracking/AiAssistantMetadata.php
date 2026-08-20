<?php

declare(strict_types=1);

namespace App\Matomo\BotTracking;

use App\Matomo\BotTracking\Events\AiAssistantDefinitionsCollecting;
use Illuminate\Contracts\Events\Dispatcher;

final class AiAssistantMetadata
{
    /** @var array<string, string> */
    private const array DISPLAY_NAMES = [
        'ChatGPT-User' => 'ChatGPT',
        'MistralAI-User' => 'Le Chat',
        'Gemini-Deep-Research' => 'Gemini',
        'Claude-User' => 'Claude',
        'Perplexity-User' => 'Perplexity',
        'Google-NotebookLM' => 'NotebookLM',
    ];

    /** @var array<string, string> */
    private const array DEFINITIONS = [
        'chatgpt.com' => 'ChatGPT',
        'chat.mistral.ai' => 'Le Chat',
        'gemini.google.com' => 'Gemini',
        'claude.ai' => 'Claude',
        'perplexity.ai' => 'Perplexity',
        'notebooklm.google.com' => 'NotebookLM',
    ];

    /** @var array<string, string>|null */
    private ?array $definitions = null;

    public function __construct(private readonly Dispatcher $events) {}

    public function displayName(string $archivedName): string
    {
        return self::DISPLAY_NAMES[$archivedName] ?? $archivedName;
    }

    public function domain(string $displayName): ?string
    {
        foreach ($this->definitions() as $domain => $name) {
            if ($name === $displayName) {
                return $domain;
            }
        }

        return null;
    }

    public function logo(string $displayName): string
    {
        $domain = $this->domain($displayName);

        if ($domain === null || preg_match('/^[a-z0-9.-]+$/iD', $domain) !== 1) {
            $domain = 'xx';
        }

        return 'plugins/Morpheus/icons/dist/aiAssistants/'.$domain.'.png';
    }

    /** @return array<string, string> */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $event = new AiAssistantDefinitionsCollecting(self::DEFINITIONS);
        $this->events->dispatch($event);
        $this->definitions = array_filter(
            $event->definitions,
            static fn (string $name, string $domain): bool => $domain !== '' && $name !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        return $this->definitions;
    }
}
