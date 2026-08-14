<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface SiteDetailsPresenter
{
    /**
     * @param  array<string, int|string|null>  $site
     * @return array<string, int|string|null>
     */
    public function present(array $site, string $language, bool $includeCreator): array;
}
