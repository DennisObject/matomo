<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserPreferenceDefaults
{
    public function reportDate(): string;
}
