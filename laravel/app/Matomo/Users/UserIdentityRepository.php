<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserIdentityRepository
{
    public function loginExists(string $login): bool;

    public function emailExists(string $email): bool;

    public function loginForEmail(string $email): ?string;
}
