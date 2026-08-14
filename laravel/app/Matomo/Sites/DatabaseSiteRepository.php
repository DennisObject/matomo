<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseSiteRepository implements SiteRepository
{
    private const array INTEGER_PROPERTIES = [
        'idsite',
        'ecommerce',
        'sitesearch',
        'exclude_unknown_urls',
        'keep_url_fragment',
    ];

    public function __construct(private ConnectionInterface $connection) {}

    public function allIds(): array
    {
        try {
            return array_values(
                $this->connection
                    ->table('site')
                    ->pluck('idsite')
                    ->map(static fn (mixed $idSite): int => (int) $idSite)
                    ->all(),
            );
        } catch (Exception) {
            return [];
        }
    }

    public function details(int $idSite): array
    {
        $record = $this->connection
            ->table('site')
            ->where('idsite', $idSite)
            ->first();

        return $record instanceof stdClass ? $this->normalizeDetails($record) : [];
    }

    public function allDetails(): array
    {
        $sites = [];

        foreach ($this->connection->table('site')->orderBy('idsite')->get() as $record) {
            $site = $this->normalizeDetails($record);
            $idSite = $site['idsite'] ?? null;

            if (is_int($idSite)) {
                $sites[$idSite] = $site;
            }
        }

        return $sites;
    }

    public function detailsForIds(
        array $idSites,
        ?string $pattern = null,
        ?int $limit = null,
        array $siteTypesToExclude = [],
    ): array {
        if ($idSites === []) {
            return [];
        }

        $query = $this->connection
            ->table('site as site')
            ->whereIn('site.idsite', $idSites)
            ->orderBy('site.idsite');

        if ($siteTypesToExclude !== []) {
            $query->whereNotIn('site.type', $siteTypesToExclude);
        }

        if ($pattern !== null) {
            $query->where(function (Builder $query) use ($pattern): void {
                $query->where('site.name', 'like', "%{$pattern}%")
                    ->orWhere('site.main_url', 'like', "http%{$pattern}%")
                    ->orWhere('site.group', 'like', "%{$pattern}%");

                if (is_numeric($pattern)) {
                    $query->orWhere('site.idsite', $pattern);
                }
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $sites = [];

        foreach ($query->get() as $record) {
            $sites[] = $this->normalizeDetails($record);
        }

        return $sites;
    }

    public function detailsInGroup(string $group): array
    {
        $sites = [];

        foreach ($this->connection->table('site')->where('group', $group)->get() as $record) {
            $sites[] = $this->normalizeDetails($record);
        }

        return $sites;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function normalizeDetails(stdClass $record): array
    {
        $site = [];

        foreach (get_object_vars($record) as $name => $value) {
            if (in_array($name, self::INTEGER_PROPERTIES, true)
                && (is_bool($value) || is_int($value) || is_string($value))) {
                $site[$name] = (int) $value;
            } elseif (is_int($value) || is_string($value) || $value === null) {
                $site[$name] = $value;
            }
        }

        return $site;
    }

    public function groups(): array
    {
        return array_values(
            $this->connection
                ->table('site')
                ->distinct()
                ->pluck('group')
                ->filter(static fn (mixed $group): bool => is_string($group))
                ->map(static fn (string $group): string => trim($group))
                ->all(),
        );
    }

    public function urls(int $idSite): array
    {
        $mainUrl = $this->connection
            ->table('site')
            ->where('idsite', $idSite)
            ->value('main_url');
        $aliases = $this->connection
            ->table('site_url')
            ->where('idsite', $idSite)
            ->pluck('url')
            ->filter(static fn (mixed $url): bool => is_string($url))
            ->all();

        return array_values([
            ...(is_string($mainUrl) ? [$mainUrl] : []),
            ...$aliases,
        ]);
    }

    public function aliasUrlsForIds(array $idSites): array
    {
        if ($idSites === []) {
            return [];
        }

        $urls = [];
        $records = $this->connection
            ->table('site_url')
            ->select(['idsite', 'url'])
            ->whereIn('idsite', $idSites)
            ->get();

        foreach ($records as $record) {
            $idSite = $record->idsite ?? null;
            $url = $record->url ?? null;

            if ((is_int($idSite) || is_string($idSite)) && is_string($url)) {
                $urls[(int) $idSite][] = $url;
            }
        }

        return $urls;
    }

    public function excludedReferrers(int $idSite): ?string
    {
        $value = $this->connection
            ->table('site')
            ->where('idsite', $idSite)
            ->value('excluded_referrers');

        return is_string($value) ? $value : null;
    }

    public function excludedParameters(int $idSite): ?string
    {
        $value = $this->connection
            ->table('site')
            ->where('idsite', $idSite)
            ->value('excluded_parameters');

        return is_string($value) ? $value : null;
    }

    public function timezones(): array
    {
        return array_values(
            $this->connection
                ->table('site')
                ->distinct()
                ->pluck('timezone')
                ->filter(static fn (mixed $timezone): bool => is_string($timezone))
                ->all(),
        );
    }

    public function idsInTimezones(array $timezones): array
    {
        if ($timezones === []) {
            return [];
        }

        return array_values(
            $this->connection
                ->table('site')
                ->whereIn('timezone', $timezones)
                ->orderBy('idsite')
                ->pluck('idsite')
                ->map(static fn (mixed $idSite): int => (int) $idSite)
                ->all(),
        );
    }

    public function idsForUrls(array $urls, array $allowedSiteIds): array
    {
        if ($urls === [] || $allowedSiteIds === []) {
            return [];
        }

        $aliases = $this->connection
            ->table('site_url')
            ->select('idsite')
            ->whereIn('url', $urls)
            ->whereIn('idsite', $allowedSiteIds);
        $records = $this->connection
            ->table('site')
            ->select('idsite')
            ->whereIn('main_url', $urls)
            ->whereIn('idsite', $allowedSiteIds)
            ->union($aliases)
            ->get();
        $siteIds = [];

        foreach ($records as $record) {
            $idSite = $record->idsite ?? null;

            if (is_int($idSite) || is_string($idSite)) {
                $siteIds[] = ['idsite' => (string) $idSite];
            }
        }

        return $siteIds;
    }
}
