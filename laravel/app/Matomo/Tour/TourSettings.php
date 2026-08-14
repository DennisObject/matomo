<?php

declare(strict_types=1);

namespace App\Matomo\Tour;

interface TourSettings
{
    public function usersAdminEnabled(): bool;

    public function sitesAdminEnabled(): bool;

    public function generalSettingsAdminEnabled(): bool;

    public function geolocationAdminEnabled(): bool;

    public function customLogoEnabled(): bool;

    public function browserArchivingTriggerEnabled(): bool;
}
