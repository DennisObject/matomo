<?php

declare(strict_types=1);

namespace App\Matomo\BotTracking\Events;

/**
 * Lets plugins add or replace AI assistant domain-to-name definitions used by report metadata.
 *
 * Listeners may update {@see $definitions}. Domains are keys and display names are values.
 */
final class AiAssistantDefinitionsCollecting
{
    /** @param array<string, string> $definitions */
    public function __construct(public array $definitions) {}
}
