<?php

declare(strict_types=1);

namespace App\Matomo\Annotations;

use Illuminate\Database\ConnectionInterface;

/**
 * @phpstan-import-type Annotation from AnnotationRepository
 */
final readonly class DatabaseAnnotationRepository implements AnnotationRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function create(int $siteId, string $date, string $note, bool $starred, string $login): array
    {
        $id = (int) $this->connection->table('annotations')->insertGetId([
            'idsite' => $siteId,
            'date' => $date,
            'note' => $note,
            'starred' => (int) $starred,
            'user' => $login,
        ]);

        return [
            'id' => $id,
            'idsite' => $siteId,
            'date' => $date,
            'note' => $note,
            'starred' => (int) $starred,
            'user' => $login,
        ];
    }

    public function find(int $siteId, int $noteId): ?array
    {
        $row = $this->connection
            ->table('annotations')
            ->where('id', $noteId)
            ->where('idsite', $siteId)
            ->first();

        return $row === null ? null : $this->record($row);
    }

    public function update(int $siteId, int $noteId, array $values): ?array
    {
        if ($values !== []) {
            $this->connection
                ->table('annotations')
                ->where('id', $noteId)
                ->where('idsite', $siteId)
                ->update($values);
        }

        return $this->find($siteId, $noteId);
    }

    public function delete(int $siteId, int $noteId): void
    {
        $this->connection
            ->table('annotations')
            ->where('id', $noteId)
            ->where('idsite', $siteId)
            ->delete();
    }

    public function deleteAll(int $siteId): void
    {
        $this->connection->table('annotations')->where('idsite', $siteId)->delete();
    }

    public function forSite(
        int $siteId,
        ?string $startDate,
        ?string $endDate,
        ?int $limit = null,
    ): array {
        $inclusiveEnd = $endDate !== null && strlen($endDate) === 10
            ? $endDate.' 23:59:59'
            : $endDate;
        $query = $this->connection
            ->table('annotations')
            ->where('idsite', $siteId)
            ->when($startDate !== null, static fn ($query) => $query->where('date', '>=', $startDate))
            ->when($inclusiveEnd !== null, static fn ($query) => $query->where('date', '<=', $inclusiveEnd))
            ->orderBy('date')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->get()->map($this->record(...))->all());
    }

    /** @return Annotation */
    private function record(object $row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'idsite' => (int) ($row->idsite ?? 0),
            'date' => (string) ($row->date ?? ''),
            'note' => (string) ($row->note ?? ''),
            'starred' => (int) ($row->starred ?? 0),
            'user' => (string) ($row->user ?? ''),
        ];
    }
}
