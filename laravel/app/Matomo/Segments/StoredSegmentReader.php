<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use App\Matomo\Archiving\SegmentDefinitionValidator;
use InvalidArgumentException;

/** @phpstan-import-type StoredSegment from StoredSegmentRepository */
final readonly class StoredSegmentReader
{
    public function __construct(
        private StoredSegmentRepository $segments,
        private SegmentDefinitionValidator $definitions,
    ) {}

    /** @return StoredSegment|null */
    public function find(int $segmentId): ?array
    {
        return $this->segments->find($segmentId);
    }

    public function validateDefinition(string $definition): void
    {
        $this->definitions->validate($definition);
    }

    /**
     * @param  list<int>  $viewableSiteIds
     * @return list<StoredSegment>
     */
    public function visible(
        string $login,
        bool $superUser,
        ?int $siteId,
        array $viewableSiteIds,
    ): array {
        $mine = [];
        $shared = [];
        $other = [];

        foreach ($this->segments->visible($login, $superUser, $siteId) as $segment) {
            $segmentSiteId = (int) ($segment['enable_only_idsite'] ?? 0);

            if (! $superUser
                && $segmentSiteId !== 0
                && ! in_array($segmentSiteId, $viewableSiteIds, true)) {
                continue;
            }

            try {
                $this->definitions->validate($segment['definition']);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($segment['login'] === $login) {
                $mine[] = $segment;
            } elseif ($segment['enable_all_users'] === 1) {
                $shared[] = $segment;
            } else {
                $other[] = $segment;
            }
        }

        return [...$mine, ...$shared, ...$other];
    }
}
