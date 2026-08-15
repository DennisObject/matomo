<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ResolvedSegmentCondition
{
    /**
     * @param  string  $expression  Fixed registry or validated dynamic column.
     * @param  literal-string  $type
     * @param  list<string>  $unionExpressions
     */
    public function __construct(
        public SegmentCondition $condition,
        public string $expression,
        public string $type,
        public ?ActionSegmentDefinition $action = null,
        public ?ConversionSegmentDefinition $conversion = null,
        public array $unionExpressions = [],
        public bool $conversionRoot = false,
    ) {}

    public function isDirectConversion(): bool
    {
        return $this->conversionRoot && $this->conversion?->scope === 'conversion';
    }

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
            && ! $this->isDirectConversion()
            && $this->condition->value !== ''
            && in_array($this->condition->operator, ['!=', '!@'], true);
    }

    public function isNegativeRelated(): bool
    {
        return $this->isNegativeAction() || $this->isNegativeConversion();
    }

    public function requiresMissingRelationBranch(): bool
    {
        if ($this->isDirectConversion()) {
            return false;
        }

        if ($this->condition->operator !== '==' || $this->condition->value !== '') {
            return false;
        }

        if ($this->action !== null) {
            return in_array($this->action->source, ['direct', 'direct-union', 'type'], true);
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

        return $this->isDirectConversion() ? null : $this->conversion?->scope;
    }
}
