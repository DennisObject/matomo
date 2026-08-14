<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

use Exception;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseSiteRepository implements SiteRepository
{
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
