<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface RawAnonymisationScheduler
{
    /**
     * @param  list<int>|null  $idSites
     * @param  list<string>  $visitColumns
     * @param  list<string>  $actionColumns
     */
    public function schedule(
        string $requester,
        ?array $idSites,
        string $startDateTime,
        string $endDateTime,
        bool $anonymizeIp,
        bool $anonymizeLocation,
        bool $anonymizeUserId,
        array $visitColumns,
        array $actionColumns,
    ): int;
}
