<?php

declare(strict_types=1);

namespace App\Matomo\Sites\Events;

final class JavascriptTrackingCodeGenerating
{
    /**
     * @param  array<string, bool|int|string>  $code
     * @param  array<string, bool|string|array<mixed>>  $parameters
     */
    public function __construct(public array $code, public readonly array $parameters) {}
}
