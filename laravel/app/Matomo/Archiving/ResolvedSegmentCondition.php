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
        public ?ConversionSegmentDefinition $conversion = null,
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

    public function isConversion(): bool
    {
        return $this->conversion !== null;
    }

    public function isNegativeConversion(): bool
    {
        return $this->conversion !== null
            && $this->condition->value !== ''
            && in_array($this->condition->operator, ['!=', '!@'], true);
    }

    public function isNegativeRelated(): bool
    {
        return $this->isNegativeAction() || $this->isNegativeConversion();
    }

    public function requiresMissingRelationBranch(): bool
    {
        if ($this->condition->operator !== '==' || $this->condition->value !== '') {
            return false;
        }

        if ($this->action !== null) {
            return in_array($this->action->source, ['direct', 'type'], true);
        }

        return $this->conversion->includeMissingOnEmpty ?? false;
    }

    public function combinesOnSameRow(): bool
    {
        return $this->conversion->combineOnSameRow ?? true;
    }

    /** @return 'action'|'conversion'|'item'|null */
    public function relatedScope(): ?string
    {
        if ($this->action !== null && $this->action->source !== 'visit-lookup') {
            return 'action';
        }

        return $this->conversion?->scope;
    }
}
