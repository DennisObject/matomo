<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Holds SQL assembled only from the fixed segment registry and Laravel's grammar.
 * Request values must never be passed to this class and remain query bindings.
 */
final readonly class TrustedSegmentSqlExpression implements Expression
{
    public function __construct(private string $trustedValue) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->trustedValue;
    }
}
