<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class VisitsSummaryRequest
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<string>|null  $columns
     * @param  list<string>  $showColumns
     * @param  list<string>  $hideColumns
     */
    public function __construct(
        public array $siteIds,
        public bool $allSites,
        public string $period,
        public string $date,
        public ?string $segment,
        public ?array $columns,
        public array $showColumns,
        public array $hideColumns,
        public ?int $idSubtable,
        public bool $expanded,
        public ?string $secondaryDimension,
        public bool $flat,
        public bool $showDimensions,
        public ?string $typeReferrer,
        public bool $setReferrerTypeLabel,
    ) {}
}
