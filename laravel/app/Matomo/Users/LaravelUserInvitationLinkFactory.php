<?php

declare(strict_types=1);

namespace App\Matomo\Users;

final readonly class LaravelUserInvitationLinkFactory implements UserInvitationLinkFactory
{
    public function __construct(private string $applicationUrl) {}

    public function make(#[\SensitiveParameter] string $token): string
    {
        return rtrim($this->applicationUrl, '/').'/index.php?'.http_build_query([
            'module' => 'Login',
            'action' => 'acceptInvitation',
            'token' => $token,
        ]);
    }
}
