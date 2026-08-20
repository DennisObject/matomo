<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

interface MutableLanguagePreferenceRepository extends LanguagePreferenceRepository
{
    public function setLanguage(string $login, string $language): bool;

    public function uses12HourClock(string $login): bool;

    public function set12HourClock(string $login, bool $use12HourClock): bool;
}
