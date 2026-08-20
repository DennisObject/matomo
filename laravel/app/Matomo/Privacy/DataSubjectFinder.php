<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface DataSubjectFinder
{
    /**
     * @param  list<int>  $siteIds
     * @return list<array<string, float|int|string|null>>
     */
    public function find(array $siteIds, string $segment, string $language): array;
}
