<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class GoalDefinition
{
    public function __construct(
        public string $name,
        public string $matchAttribute,
        public string $pattern,
        public string $patternType,
        public bool $caseSensitive,
        public float $revenue,
        public bool $allowMultipleConversionsPerVisit,
        public string $description,
        public bool $useEventValueAsRevenue,
    ) {}

    /** @return array<string, float|int|string> */
    public function storedValues(bool $includeDeleted = false): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'match_attribute' => $this->matchAttribute,
            'pattern' => $this->pattern,
            'pattern_type' => $this->patternType,
            'case_sensitive' => (int) $this->caseSensitive,
            'allow_multiple' => (int) $this->allowMultipleConversionsPerVisit,
            'revenue' => $this->revenue,
            ...($includeDeleted ? ['deleted' => 0] : []),
            'event_value_as_revenue' => (int) $this->useEventValueAsRevenue,
        ];
    }
}
