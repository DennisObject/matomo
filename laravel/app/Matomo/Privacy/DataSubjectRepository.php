<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface DataSubjectRepository
{
    /**
     * @param  list<array{idsite: int, idvisit: int}>  $visits
     * @return array<string, list<array<string, mixed>>>
     */
    public function export(array $visits): array;

    /**
     * @param  list<array{idsite: int, idvisit: int}>  $visits
     * @return array<string, int>
     */
    public function delete(array $visits): array;
}
