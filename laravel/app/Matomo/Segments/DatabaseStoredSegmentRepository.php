<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use stdClass;

/** @phpstan-import-type StoredSegment from StoredSegmentRepository */
final readonly class DatabaseStoredSegmentRepository implements MutableStoredSegmentRepository
{
    /** @param Closure(): Connection $connection */
    public function __construct(private Closure $connection) {}

    public function find(int $segmentId): ?array
    {
        $row = $this->connection()->table('segment')->where('idsegment', $segmentId)->first();

        return $row instanceof stdClass ? $this->normalize($row) : null;
    }

    public function visible(string $login, bool $superUser, ?int $siteId): array
    {
        $query = $this->connection()->table('segment')
            ->where('deleted', 0)
            ->when($siteId !== null, static function (Builder $query) use ($siteId): void {
                $query->where(static function (Builder $sites) use ($siteId): void {
                    $sites->where('enable_only_idsite', $siteId)->orWhere('enable_only_idsite', 0);
                });
            })
            ->when(! $superUser, static function (Builder $query) use ($login): void {
                $query->where(static function (Builder $visibility) use ($login): void {
                    $visibility->where('enable_all_users', 1)->orWhere('login', $login);
                });
            })
            ->orderBy('name');
        $segments = [];

        foreach ($query->get() as $row) {
            $segments[] = $this->normalize($row);
        }

        return $segments;
    }

    public function delete(int $segmentId, string $editedAt): void
    {
        $this->connection()->table('segment')->where('idsegment', $segmentId)->update([
            'deleted' => 1,
            'ts_last_edit' => $editedAt,
        ]);
    }

    public function create(array $values): int
    {
        $definition = $values['definition'] ?? null;

        if (! is_string($definition)) {
            return 0;
        }

        $values['hash'] = md5(urldecode($definition));

        return (int) $this->connection()->table('segment')->insertGetId($values, 'idsegment');
    }

    public function update(int $segmentId, array $values): bool
    {
        if (isset($values['definition']) && is_string($values['definition'])) {
            $values['hash'] = md5(urldecode($values['definition']));
        }

        return $this->connection()
            ->table('segment')
            ->where('idsegment', $segmentId)
            ->update($values) > 0;
    }

    /** @return StoredSegment */
    private function normalize(stdClass $row): array
    {
        $siteId = $row->enable_only_idsite ?? null;

        return [
            'idsegment' => (int) ($row->idsegment ?? 0),
            'name' => is_string($row->name ?? null) ? $row->name : '',
            'definition' => is_string($row->definition ?? null) ? $row->definition : '',
            'hash' => is_string($row->hash ?? null) ? $row->hash : '',
            'login' => is_string($row->login ?? null) ? $row->login : '',
            'enable_all_users' => (int) ($row->enable_all_users ?? 0),
            'enable_only_idsite' => $siteId === null ? null : (int) $siteId,
            'auto_archive' => (int) ($row->auto_archive ?? 0),
            'ts_created' => is_string($row->ts_created ?? null) ? $row->ts_created : null,
            'ts_last_edit' => is_string($row->ts_last_edit ?? null) ? $row->ts_last_edit : null,
            'deleted' => (int) ($row->deleted ?? 0),
            'starred' => (int) ($row->starred ?? 0),
            'starred_by' => is_string($row->starred_by ?? null) ? $row->starred_by : null,
        ];
    }

    private function connection(): Connection
    {
        return ($this->connection)();
    }
}
