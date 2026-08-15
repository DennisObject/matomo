<?php

declare(strict_types=1);

namespace App\Matomo\Annotations;

/**
 * @phpstan-type Annotation array{id: int, idsite: int, date: string, note: string, starred: int, user: string}
 */
interface AnnotationRepository
{
    /** @return Annotation */
    public function create(int $siteId, string $date, string $note, bool $starred, string $login): array;

    /** @return Annotation|null */
    public function find(int $siteId, int $noteId): ?array;

    /**
     * @param  array{date?: string, note?: string, starred?: int}  $values
     * @return Annotation|null
     */
    public function update(int $siteId, int $noteId, array $values): ?array;

    public function delete(int $siteId, int $noteId): void;

    public function deleteAll(int $siteId): void;

    /** @return list<Annotation> */
    public function forSite(int $siteId, ?string $startDate, ?string $endDate, ?int $limit = null): array;
}
