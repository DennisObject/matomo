<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface AnonymisationSettingsRepository
{
    /** @return array<string, bool|int|string> */
    public function values(?int $idSite): array;

    public function usesSiteSettings(int $idSite): bool;

    /** @param array<string, bool|int|string> $values */
    public function replace(?int $idSite, array $values): void;

    public function removeSiteSettings(int $idSite): void;
}
