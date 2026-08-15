<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Options\OptionRepository;
use App\Matomo\Privacy\Events\LogsOlderThanDeleting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class DatabaseDataPurger implements DataPurger
{
    private const int CHUNK_SIZE = 25_000;

    public function __construct(
        private Connection $connection,
        private OptionRepository $options,
        private Dispatcher $events,
    ) {}

    public function purge(): void
    {
        if ($this->enabled('delete_logs_enable')) {
            $this->purgeLogs($this->positive('delete_logs_older_than', 180));
        }

        if ($this->enabled('delete_reports_enable')) {
            $this->purgeReports($this->positive('delete_reports_older_than', 3));
        }
    }

    private function purgeLogs(int $olderThanDays): void
    {
        $cutoff = CarbonImmutable::today('UTC')->subDays($olderThanDays);
        do {
            $visitIds = $this->connection->table('log_visit')
                ->where('visit_last_action_time', '<', $cutoff->toDateTimeString())
                ->orderBy('idvisit')->limit(self::CHUNK_SIZE)->pluck('idvisit')->all();
            if ($visitIds === []) {
                break;
            }

            $this->connection->transaction(function () use ($visitIds): void {
                foreach (['log_conversion_item', 'log_conversion', 'log_link_visit_action'] as $table) {
                    if ($this->connection->getSchemaBuilder()->hasTable($table)) {
                        $this->connection->table($table)->whereIn('idvisit', $visitIds)->delete();
                    }
                }

                $this->connection->table('log_visit')->whereIn('idvisit', $visitIds)->delete();
            });
        } while (count($visitIds) === self::CHUNK_SIZE);

        $this->deleteUnusedActions();
        $this->events->dispatch(new LogsOlderThanDeleting($cutoff, $olderThanDays));
    }

    private function deleteUnusedActions(): void
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('log_action')) {
            return;
        }

        $references = [
            'log_link_visit_action' => [
                'idaction_url', 'idaction_url_ref', 'idaction_name', 'idaction_name_ref',
                'idaction_event_category', 'idaction_event_action',
                'idaction_content_name', 'idaction_content_piece', 'idaction_content_target',
            ],
            'log_conversion' => ['idaction_url'],
            'log_conversion_item' => [
                'idaction_sku', 'idaction_name', 'idaction_category', 'idaction_category2',
                'idaction_category3', 'idaction_category4', 'idaction_category5',
            ],
        ];
        $columns = $this->connection->getSchemaBuilder();
        $this->connection->table('log_action')->where(function (Builder $actions) use ($references, $columns): void {
            foreach ($references as $table => $names) {
                if (! $columns->hasTable($table)) {
                    continue;
                }

                foreach ($names as $column) {
                    if (! $columns->hasColumn($table, $column)) {
                        continue;
                    }

                    $actions->whereNotExists(function (Builder $used) use ($table, $column): void {
                        $used->selectRaw('1')->from($table)
                            ->whereColumn($table.'.'.$column, 'log_action.idaction');
                    });
                }
            }
        })->delete();
    }

    private function purgeReports(int $olderThanMonths): void
    {
        $cutoff = CarbonImmutable::today('UTC')->startOfMonth()->subMonths($olderThanMonths + 1);
        foreach ($this->archiveMonths() as $month => $tables) {
            $date = CarbonImmutable::createFromFormat('!Y_m', $month, 'UTC');
            if ($date === null || $date->greaterThan($cutoff)) {
                continue;
            }

            $this->purgeArchiveMonth($tables['numeric'] ?? null, $tables['blob'] ?? null);
        }
    }

    /**
     * @return array<string, array{numeric?: string, blob?: string}>
     */
    private function archiveMonths(): array
    {
        $result = [];
        foreach ($this->connection->getSchemaBuilder()->getTableListing() as $table) {
            if (preg_match('/(?:^|[._])archive_(numeric|blob)_([0-9]{4}_[0-9]{2})$/D', $table, $matches) !== 1) {
                continue;
            }

            $result[$matches[2]][$matches[1]] = $table;
        }

        return $result;
    }

    private function purgeArchiveMonth(?string $numeric, ?string $blob): void
    {
        $keepPeriods = [];
        foreach ([1 => 'day', 2 => 'week', 3 => 'month', 4 => 'year', 5 => 'range'] as $period => $name) {
            if ($this->enabled('delete_reports_keep_'.$name.'_reports')) {
                $keepPeriods[] = $period;
            }
        }

        $keepSegments = $this->enabled('delete_reports_keep_segment_reports');
        $keepBasic = $this->enabled('delete_reports_keep_basic_metrics');

        if ($keepPeriods === [] && ! $keepSegments && ! $keepBasic) {
            foreach ([$blob, $numeric] as $table) {
                if ($table !== null) {
                    $this->connection->getSchemaBuilder()->drop($table);
                }
            }

            return;
        }

        if ($numeric === null) {
            return;
        }

        $keepIds = $this->connection->table($numeric)->where(function (Builder $query) use ($keepPeriods, $keepSegments): void {
            if ($keepPeriods !== []) {
                $query->whereIn('period', $keepPeriods);
            }

            if ($keepSegments) {
                $method = $keepPeriods === [] ? 'where' : 'orWhere';
                $query->{$method}('name', 'like', 'done%');
                $query->where('name', '!=', 'done');
            }
        })->pluck('idarchive')->unique()->all();

        if ($blob !== null) {
            $query = $this->connection->table($blob);
            $keepIds === [] ? $query->delete() : $query->whereNotIn('idarchive', $keepIds)->delete();
        }

        $query = $this->connection->table($numeric)->where('name', 'not like', 'done%');
        if ($keepIds !== []) {
            $query->whereNotIn('idarchive', $keepIds);
        }

        if (! $keepBasic) {
            $query->delete();
        }
    }

    private function enabled(string $name): bool
    {
        return (int) ($this->options->value($name) ?? 0) === 1;
    }

    private function positive(string $name, int $default): int
    {
        return max(1, (int) ($this->options->value($name) ?? $default));
    }
}
