<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

interface LanguagePreferenceRepository
{
    public function forLogin(string $login): ?string;
}
