<?php

declare(strict_types=1);

namespace App\Matomo\Api\Events;

final class ComparisonPagesCollecting
{
    /** @var list<string> */
    public array $pages = [];
}
