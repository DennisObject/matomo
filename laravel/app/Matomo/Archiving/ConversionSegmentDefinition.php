<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ConversionSegmentDefinition
{
    /**
     * @param  'conversion'|'item'  $scope
     * @param  'direct'|'goal-name'|'lookup'  $source
     * @param  literal-string  $expression
     * @param  literal-string  $type
     * @param  list<array{literal-string, literal-string, int}>  $lookupColumns
     * @param  literal-string|null  $discriminatorColumn
     * @param  list<string>  $operators
     */
    public function __construct(
        public string $scope,
        public string $source,
        public string $expression,
        public string $type,
        public array $lookupColumns = [],
        public ?string $discriminatorColumn = null,
        public ?int $discriminatorValue = null,
        public array $operators = ['==', '!=', '>', '<', '>=', '<=', '=@', '!@', '=^', '=$'],
        public bool $includeMissingOnEmpty = false,
        public bool $combineOnSameRow = true,
    ) {}

    public function supports(string $operator): bool
    {
        return in_array($operator, $this->operators, true);
    }
}
