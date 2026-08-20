<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class TrackerCookieSettings
{
    /**
     * @param  array<int, string>  $namesBySite
     * @param  array<int, int>  $expireSecondsBySite
     * @param  array<int, string>  $pathsBySite
     * @param  array<int, string>  $domainsBySite
     */
    public function __construct(
        private string $name,
        private int $expireSeconds,
        private string $path,
        private string $domain,
        private array $namesBySite = [],
        private array $expireSecondsBySite = [],
        private array $pathsBySite = [],
        private array $domainsBySite = [],
    ) {}

    public function name(?int $siteId = null): string
    {
        return $siteId !== null && array_key_exists($siteId, $this->namesBySite)
            ? $this->namesBySite[$siteId]
            : $this->name;
    }

    public function expireSeconds(?int $siteId = null): int
    {
        return $siteId !== null && array_key_exists($siteId, $this->expireSecondsBySite)
            ? $this->expireSecondsBySite[$siteId]
            : $this->expireSeconds;
    }

    public function path(?int $siteId = null): string
    {
        return $siteId !== null && array_key_exists($siteId, $this->pathsBySite)
            ? $this->pathsBySite[$siteId]
            : $this->path;
    }

    public function domain(?int $siteId = null): string
    {
        return $siteId !== null && array_key_exists($siteId, $this->domainsBySite)
            ? $this->domainsBySite[$siteId]
            : $this->domain;
    }
}
