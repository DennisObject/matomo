<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserInvitationNotifier
{
    public function notify(
        string $login,
        string $email,
        #[\SensitiveParameter] string $token,
        int $expiryDays,
    ): void;
}
