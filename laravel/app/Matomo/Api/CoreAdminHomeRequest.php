<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class CoreAdminHomeRequest
{
    public function __construct(
        public ?int $siteId,
        public int|string|null $failureId,
        public ?bool $browserTriggerArchivingEnabled = null,
        public ?int $todayArchiveTimeToLive = null,
        /** @var list<string> */
        public array $trustedHosts = [],
        public ?bool $useCustomLogo = null,
        public ?bool $hasCustomLogo = null,
        public ?bool $hasCustomFavicon = null,
    ) {}
}
