<?php

declare(strict_types=1);

namespace App\Matomo\UserChanges;

use App\Matomo\UserChanges\Events\ChangesFiltering;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;

final readonly class DatabaseUserChangeReadRepository implements UserChangeReadRepository
{
    public function __construct(
        private Connection $connection,
        private Dispatcher $events,
    ) {}

    public function markAllRead(string $login): bool
    {
        if (! $this->connection->table('user')->where('login', $login)->exists()) {
            return false;
        }

        $maximumId = $this->maximumVisibleChangeId();

        if ($maximumId > 0) {
            $this->connection
                ->table('user')
                ->where('login', $login)
                ->update(['idchange_last_viewed' => $maximumId]);
        }

        return true;
    }

    private function maximumVisibleChangeId(): int
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('changes')) {
            return 0;
        }

        $changes = [];
        $records = $this->connection
            ->table('changes')
            ->whereNotNull('title')
            ->where(
                'created_time',
                '>',
                CarbonImmutable::now('UTC')->subMonthsNoOverflow(6)->format('Y-m-d H:i:s'),
            )
            ->orderByDesc('idchange')
            ->get();

        foreach ($records as $record) {
            $changes[] = $this->row($record);
        }

        $event = new ChangesFiltering($changes);
        $this->events->dispatch($event);
        $maximumId = 0;

        foreach ($event->changes as $change) {
            $id = $change['idchange'] ?? null;

            if ((is_int($id) || is_string($id)) && is_numeric($id)) {
                $maximumId = max($maximumId, (int) $id);
            }
        }

        return $maximumId;
    }

    /** @return array<string, mixed> */
    private function row(object $record): array
    {
        $row = get_object_vars($record);
        $id = $row['idchange'] ?? null;

        if (is_int($id) || is_string($id)) {
            $row['idchange'] = (int) $id;
        }

        return $row;
    }
}
