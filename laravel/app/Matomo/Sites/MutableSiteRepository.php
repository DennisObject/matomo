<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface MutableSiteRepository extends SiteRepository
{
    /**
     * @param  array<string, bool|int|string|null>  $values
     * @param  list<string>  $urls
     * @param  array<string, list<array{name: string, value: mixed}>>  $settings
     */
    public function create(array $values, array $urls, array $settings): int;

    /**
     * @param  array<string, bool|int|string|null>  $values
     * @param  list<string>|null  $urls
     * @param  array<string, list<array{name: string, value: mixed}>>  $settings
     */
    public function update(int $idSite, array $values, ?array $urls, array $settings): bool;

    /** @return 'deleted'|'last-site'|'not-found' */
    public function delete(int $idSite): string;
}
