<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface SiteRepository
{
    /**
     * @return list<int>
     */
    public function allIds(): array;

    /**
     * @return array<string, int|string|null>
     */
    public function details(int $idSite): array;

    public function mainUrl(int $idSite): ?string;

    /**
     * @return array<int, array<string, int|string|null>>
     */
    public function allDetails(): array;

    /**
     * @param  list<int>  $idSites
     * @param  list<string>  $siteTypesToExclude
     * @return list<array<string, int|string|null>>
     */
    public function detailsForIds(
        array $idSites,
        ?string $pattern = null,
        ?int $limit = null,
        array $siteTypesToExclude = [],
    ): array;

    /**
     * @return list<array<string, int|string|null>>
     */
    public function detailsInGroup(string $group): array;

    /**
     * @return list<string>
     */
    public function groups(): array;

    /**
     * @return list<string>
     */
    public function urls(int $idSite): array;

    /**
     * @param  list<int>  $idSites
     * @return array<int, list<string>>
     */
    public function aliasUrlsForIds(array $idSites): array;

    public function excludedReferrers(int $idSite): ?string;

    public function excludedParameters(int $idSite): ?string;

    /**
     * @return list<string>
     */
    public function timezones(): array;

    /**
     * @param  list<string>  $timezones
     * @return list<int>
     */
    public function idsInTimezones(array $timezones): array;

    /**
     * @param  list<string>  $urls
     * @param  list<int>  $allowedSiteIds
     * @return list<array{idsite: string}>
     */
    public function idsForUrls(array $urls, array $allowedSiteIds): array;
}
