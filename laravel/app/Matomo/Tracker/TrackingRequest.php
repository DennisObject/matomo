<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class TrackingRequest
{
    public function __construct(
        public int $siteId,
        public string $url,
        public string $actionName,
        public string $visitorId,
        public string $ipAddress,
        public string $userAgent,
        public int $actionType = 1,
        public ?string $eventCategory = null,
        public ?string $eventAction = null,
        public ?string $eventName = null,
        public ?float $eventValue = null,
        public ?string $userId = null,
        public string $referrerUrl = '',
        public string $browserLanguage = '',
        public string $localTime = '00:00:00',
        public string $resolution = 'unknown',
        public bool $cookiesEnabled = false,
        public int $referrerType = 1,
        public string $referrerName = '',
        public string $referrerKeyword = '',
        /** @var array<string, string> */
        public array $visitProperties = [],
        /** @var array<string, string> */
        public array $actionProperties = [],
    ) {}
}
