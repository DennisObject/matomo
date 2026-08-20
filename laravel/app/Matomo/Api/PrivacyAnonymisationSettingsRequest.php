<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyAnonymisationSettingsRequest
{
    public function __construct(
        public ?int $idSite,
        public bool $mutation,
        public ?bool $ipEnabled,
        public ?int $maskLength,
        public ?bool $useAnonymizedIpForEnrichment,
        public bool $anonymizeUserId,
        public bool $anonymizeOrderId,
        public string $anonymizeReferrer,
        public bool $forceCookielessTracking,
        public bool $randomizeConfigId,
        public bool $useSiteSpecificSettings,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
