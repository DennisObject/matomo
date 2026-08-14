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

    /**
     * @return list<string>
     */
    public function timezones(): array;
}
