<?php

declare(strict_types=1);

namespace App\Matomo\Users\Events;

final readonly class UserInvitationLinkGenerated
{
    public function __construct(public string $login, public string $email) {}
}
