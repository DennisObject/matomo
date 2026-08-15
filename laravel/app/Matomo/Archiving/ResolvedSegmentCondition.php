<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ResolvedSegmentCondition
{
    /**
     * @param  literal-string  $expression
     * @param  literal-string  $type
     */
    public function __construct(
        public SegmentCondition $condition,
        public string $expression,
        public string $type,
        public ?ActionSegmentDefinition $action = null,
    ) {}

    public function isAction(): bool
    {
        return $this->action !== null;
    }

    public function isNegativeAction(): bool
    {
        return $this->action !== null
            && $this->condition->value !== ''
            && in_array($this->condition->operator, ['!=', '!@'], true);
    }
}
