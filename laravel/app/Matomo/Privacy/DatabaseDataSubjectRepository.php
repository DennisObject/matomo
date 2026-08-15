<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Archiving\ArchiveInvalidationManager;
use App\Matomo\Privacy\Events\DataSubjectLogTablesCollecting;
use App\Matomo\Privacy\Events\DataSubjectsDeleting;
use App\Matomo\Privacy\Events\DataSubjectsExporting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseDataSubjectRepository implements DataSubjectRepository
{
    public function __construct(
        private Connection $connection,
        private Dispatcher $events,
        private ArchiveInvalidationManager $archiveInvalidations,
    ) {}

    public function export(array $visits): array
    {
        $data = [];
        $tables = array_reverse($this->tables());
        foreach ($tables as $table) {
            if (! $this->connection->getSchemaBuilder()->hasTable($table->name)) {
                continue;
            }

            $rows = $this->visitQuery($table, $visits)->orderBy($table->orderColumn)->get();
            $data[$table->name] = array_values(array_map($this->exportRow(...), $rows->all()));
        }

        $data = $this->withActionNames($data);
        $event = new DataSubjectsExporting($visits, $data);
        $this->events->dispatch($event);
        krsort($event->data);

        return $event->data;
    }

    public function delete(array $visits): array
    {
        $dates = $this->visitDates($visits);
        $deleted = $this->connection->transaction(function () use ($visits): array {
            $result = [];
            foreach ($this->tables() as $table) {
                if (! $this->connection->getSchemaBuilder()->hasTable($table->name)) {
                    continue;
                }

                $result[$table->name] = $this->visitQuery($table, $visits)->delete();
            }

            $event = new DataSubjectsDeleting($visits, $result);
            $this->events->dispatch($event);

            return $event->deleted;
        });

        if ($dates !== []) {
            $this->archiveInvalidations->invalidate(
                array_values(array_unique(array_column($visits, 'idsite'))),
                $dates,
                null,
                null,
                false,
                true,
            );
        }

        krsort($deleted);

        return $deleted;
    }

    /** @return list<DataSubjectLogTable> */
    private function tables(): array
    {
        $event = new DataSubjectLogTablesCollecting([
            new DataSubjectLogTable('log_conversion_item', 'idvisit', 'idsite', 'idvisit', 10),
            new DataSubjectLogTable('log_conversion', 'idvisit', 'idsite', 'idvisit', 20),
            new DataSubjectLogTable('log_link_visit_action', 'idvisit', 'idsite', 'idlink_va', 90),
            new DataSubjectLogTable('log_visit', 'idvisit', 'idsite', 'idvisit', 100),
        ]);
        $this->events->dispatch($event);
        usort($event->tables, static fn (DataSubjectLogTable $left, DataSubjectLogTable $right): int => $left->deletionPriority <=> $right->deletionPriority);

        return $event->tables;
    }

    /** @param list<array{idsite: int, idvisit: int}> $visits */
    private function visitQuery(DataSubjectLogTable $table, array $visits): Builder
    {
        return $this->connection->table($table->name)->where(function (Builder $query) use ($table, $visits): void {
            foreach ($visits as $visit) {
                $query->orWhere(function (Builder $pair) use ($table, $visit): void {
                    $pair->where($table->siteColumn, $visit['idsite'])
                        ->where($table->visitColumn, $visit['idvisit']);
                });
            }
        });
    }

    /** @return array<string, mixed> */
    private function exportRow(stdClass $row): array
    {
        $result = [];
        foreach ((array) $row as $column => $value) {
            if ($value === null) {
                continue;
            }

            if (is_string($value) && preg_match('//u', $value) !== 1) {
                $value = bin2hex($value);
            }

            $result[$column] = $value;
        }

        ksort($result);

        return $result;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $data
     * @return array<string, list<array<string, mixed>>>
     */
    private function withActionNames(array $data): array
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('log_action')) {
            return $data;
        }

        $ids = [];
        foreach ($data as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $column => $value) {
                    if (str_starts_with($column, 'idaction_') && is_numeric($value) && (int) $value > 0) {
                        $ids[] = (int) $value;
                    }
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return $data;
        }

        $rows = $this->connection->table('log_action')->whereIn('idaction', $ids)->orderBy('idaction')->get();
        $data['log_action'] = array_values(array_map($this->exportRow(...), $rows->all()));

        return $data;
    }

    /**
     * @param  list<array{idsite: int, idvisit: int}>  $visits
     * @return list<string>
     */
    private function visitDates(array $visits): array
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('log_visit')) {
            return [];
        }

        $table = new DataSubjectLogTable('log_visit', 'idvisit', 'idsite', 'idvisit', 100);
        $dates = $this->visitQuery($table, $visits)->pluck('visit_last_action_time')
            ->map(static fn (mixed $date): string => substr((string) $date, 0, 10))->all();

        return array_values(array_unique(array_filter($dates)));
    }
}
