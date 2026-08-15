<?php

declare(strict_types=1);

namespace App\Matomo\UserChanges;

interface UserChangeReadRepository
{
    public function markAllRead(string $login): bool;
}
