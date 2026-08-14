<?php

declare(strict_types=1);

namespace App\Matomo\ProfessionalServices;

interface PromoWidgetDismissalRepository
{
    public function dismiss(string $login, string $widgetName, int $timestamp): void;
}
