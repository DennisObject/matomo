<?php

declare(strict_types=1);

namespace App\Matomo\Tour;

interface TourDataRepository
{
    /** @return array<string, bool> */
    public function progress(string $login): array;

    public function skip(string $login, string $challengeId): void;

    public function hasTrackedData(): bool;

    public function hasAddedWebsite(string $login): bool;

    public function hasAddedScheduledReport(string $login): bool;

    public function hasCustomizedDashboard(string $login): bool;

    public function hasAddedSegment(string $login): bool;

    public function usesTwoFactorAuthentication(string $login): bool;
}
