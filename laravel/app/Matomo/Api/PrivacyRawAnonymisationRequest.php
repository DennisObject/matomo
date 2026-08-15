<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class PrivacyRawAnonymisationRequest
{
    /**
     * @param  list<string>|null  $sites
     * @param  list<string>  $visitColumns
     * @param  list<string>  $actionColumns
     */
    public function __construct(
        public ?array $sites,
        public string $date,
        public bool $anonymizeIp,
        public bool $anonymizeLocation,
        public bool $anonymizeUserId,
        public array $visitColumns,
        public array $actionColumns,
        #[\SensitiveParameter]
        public ?string $passwordConfirmation,
    ) {}
}
