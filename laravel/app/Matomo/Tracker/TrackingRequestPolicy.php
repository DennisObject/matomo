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

    public function isPrefetch(Request $request): bool;

    public function isKnownBotIp(Request $request, string $ipAddress): bool;

    public function storedIpAddress(int $siteId, string $ipAddress): string;

    public function storedOrderId(int $siteId, string $orderId): string;

    public function collectsUserId(int $siteId): bool;

    public function referrerAnonymisation(int $siteId): string;

    public function masksCampaignParameters(int $siteId): bool;

    public function collectsScreenResolution(int $siteId): bool;

    public function forcesCookielessTracking(int $siteId): bool;

    public function allowsPrivilegedOverrides(Request $request, int $siteId): bool;

    public function usesAnonymizedIpForEnrichment(int $siteId): bool;
}
