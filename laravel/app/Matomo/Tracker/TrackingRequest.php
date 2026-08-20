<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class TrackingRequest
{
    public function __construct(public int $siteId, public string $url, public string $actionName, public string $visitorId, public string $ipAddress, public string $userAgent) {}
}
