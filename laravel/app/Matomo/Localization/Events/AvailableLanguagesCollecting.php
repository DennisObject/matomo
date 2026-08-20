<?php

declare(strict_types=1);

namespace App\Matomo\Localization\Events;

final class AvailableLanguagesCollecting
{
    /** @param list<string> $languages */
    public function __construct(public array $languages) {}
}
