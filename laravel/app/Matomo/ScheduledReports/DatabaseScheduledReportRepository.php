<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabaseScheduledReportRepository implements ScheduledReportRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function create(array $report): int
    {
        return (int) $this->connection->table('report')->insertGetId($this->encode($report), 'idreport');
    }

    public function update(int $idReport, array $changes): void
    {
        $this->connection->table('report')->where('idreport', $idReport)->update($this->encode($changes));
    }

    public function find(
        ?int $idSite,
        ?string $period,
        ?int $idReport,
        ?string $login,
        ?int $idSegment,
    ): array {
        $query = $this->connection->table('report')
            ->join('site', 'site.idsite', '=', 'report.idsite')
            ->select('report.*')->where('report.deleted', 0)->orderBy('report.description');
        if ($idSite !== null) {
            $query->where('report.idsite', $idSite);
        }

        if ($period !== null) {
            $query->where('report.period', $period);
        }

        if ($idReport !== null) {
            $query->where('report.idreport', $idReport);
        }

        if ($login !== null) {
            $query->where('report.login', $login);
        }

        if ($idSegment !== null) {
            $query->where('report.idsegment', $idSegment);
        }

        return array_values($query->get()->map(function (object $row): array {
            $report = get_object_vars($row);
            $report['idreport'] = (int) ($report['idreport'] ?? 0);
            $report['idsite'] = (int) ($report['idsite'] ?? 0);
            $report['idsegment'] = isset($report['idsegment']) ? (int) $report['idsegment'] : null;
            $report['hour'] = (int) ($report['hour'] ?? 0);
            $report['deleted'] = (int) ($report['deleted'] ?? 0);
            $report['evolution_graph_within_period'] = (bool) ($report['evolution_graph_within_period'] ?? false);
            $report['evolution_graph_period_n'] = max(1, (int) ($report['evolution_graph_period_n'] ?? 30));
            $report['period_param'] = ($report['period_param'] ?? null) ?: (($report['period'] ?? '') === 'never' ? 'day' : ($report['period'] ?? 'day'));
            $report['reports'] = $this->decodeArray($report['reports'] ?? null);
            $report['parameters'] = $this->decodeArray($report['parameters'] ?? null);

            return $report;
        })->all());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function encode(array $values): array
    {
        foreach (['reports', 'parameters'] as $name) {
            if (array_key_exists($name, $values) && is_array($values[$name])) {
                $values[$name] = json_encode($values[$name], JSON_THROW_ON_ERROR);
            }
        }

        return $values;
    }

    /** @return array<mixed> */
    private function decodeArray(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
