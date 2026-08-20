<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use Illuminate\Http\Request;

interface TrackingRequestPolicy
{
    public function records(Request $request): bool;

    public function honorsDoNotTrack(Request $request): bool;

    /** @param array<string, int|string|null> $site */
    public function excludesVisit(array $site, string $ipAddress, string $userAgent): bool;

    public function storedIpAddress(int $siteId, string $ipAddress): string;

    public function collectsUserId(int $siteId): bool;

    public function referrerAnonymisation(int $siteId): string;

    public function masksCampaignParameters(int $siteId): bool;

    public function collectsScreenResolution(int $siteId): bool;
}
