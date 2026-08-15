<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class ActionSegmentDefinition
{
    /**
     * @param  literal-string  $source
     * @param  string  $expression  Fixed registry or validated dynamic column.
     * @param  literal-string  $lookupAlias
     * @param  list<int>  $actionTypes
     * @param  literal-string  $type
     * @param  list<array{literal-string, literal-string}>  $lookupColumns
     * @param  list<string>  $directExpressions
     */
    public function __construct(
        public string $source,
        public string $expression,
        public string $lookupAlias,
        public array $actionTypes,
        public string $type,
        public array $lookupColumns = [],
        public array $directExpressions = [],
    ) {}

    /** @return list<array{string, literal-string}> */
    public function columns(): array
    {
        if ($this->lookupColumns !== []) {
            return $this->lookupColumns;
        }

        return [[$this->expression, $this->lookupAlias]];
    }
}
