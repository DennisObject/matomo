<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use InvalidArgumentException;

final readonly class ActionArchiveMetric
{
    /** @var 'sum'|'min'|'max' */
    public string $aggregation;

    public function __construct(
        public string $name,
        public TrustedSegmentSqlExpression $expression,
        string $aggregation = 'sum',
        public bool $pagesOnly = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $name) !== 1
            || ! in_array($aggregation, ['sum', 'min', 'max'], true)) {
            throw new InvalidArgumentException('The action archive metric definition is invalid.');
        }

        $this->aggregation = $aggregation;
    }
}
