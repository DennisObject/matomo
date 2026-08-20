<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabaseRawAnonymisationScheduler implements RawAnonymisationScheduler
{
    public function __construct(private ConnectionInterface $connection) {}

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
    ): int {
        return (int) $this->connection->table('privacy_logdata_anonymizations')->insertGetId([
            'idsites' => $idSites === null ? null : $this->json($idSites),
            'date_start' => $startDateTime,
            'date_end' => $endDateTime,
            'anonymize_ip' => $anonymizeIp ? 1 : 0,
            'anonymize_location' => $anonymizeLocation ? 1 : 0,
            'anonymize_userid' => $anonymizeUserId ? 1 : 0,
            'unset_visit_columns' => $this->json($visitColumns),
            'unset_link_visit_action_columns' => $this->json($actionColumns),
            'scheduled_date' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            'job_start_date' => null,
            'requester' => $requester,
        ], 'idlogdata_anonymization');
    }

    /**
     * @param  list<int>|list<string>  $value
     *
     * @throws JsonException
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
