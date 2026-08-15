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
        public int $actionType,
        public ?string $eventCategory,
        public ?string $eventAction,
        public ?string $eventName,
        public ?float $eventValue,
        public ?string $searchCategory,
        public ?int $searchCount,
        public ?string $userId,
        public string $referrerUrl,
        public int $referrerType,
        public string $referrerName,
        public string $referrerKeyword,
        public string $browserLanguage,
        public string $localTime,
        public string $resolution,
        public bool $cookiesEnabled,
        /** @var array<string, string> */
        public array $visitProperties,
        /** @var array<string, string> */
        public array $actionProperties,
        /** @var array<string, int> */
        public array $performanceTimings,
    ) {}
}
