<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final readonly class DynamicSegmentDefinition
{
    /**
     * @param  'action'|'conversion'|'visit'  $scope
     * @param  non-empty-list<string>  $expressions
     */
    public function __construct(
        public string $scope,
        public array $expressions,
    ) {}
}
