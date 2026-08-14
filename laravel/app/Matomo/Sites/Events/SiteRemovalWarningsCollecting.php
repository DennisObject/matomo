<?php

declare(strict_types=1);

namespace App\Matomo\Sites\Events;

final class SiteRemovalWarningsCollecting
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(public readonly int $idSite) {}
}
