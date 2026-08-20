<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface NewsletterSubscriber
{
    public function subscribe(string $login, string $email): bool;
}
