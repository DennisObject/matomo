<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface UserInvitationLinkFactory
{
    public function make(#[\SensitiveParameter] string $token): string;
}
