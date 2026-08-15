<?php

declare(strict_types=1);

namespace App\Matomo\CoreAdmin;

interface BrandingManager
{
    /**
     * @return array{useCustomLogo: bool, customLogoPath?: string, customFaviconPath?: string}
     */
    public function update(
        string $login,
        bool $useCustomLogo,
        bool $hasCustomLogo,
        bool $hasCustomFavicon,
    ): array;
}
