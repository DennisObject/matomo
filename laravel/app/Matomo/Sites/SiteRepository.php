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
     * @return list<string>
     */
    public function groups(): array;

    /**
     * @return list<string>
     */
    public function urls(int $idSite): array;

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
