<?php

declare(strict_types=1);

namespace App\Matomo\Users;

interface MutableUserRepository
{
    /** @return 'created'|'login-exists'|'email-exists'|'login-is-email'|'email-is-login' */
    public function create(
        string $login,
        #[\SensitiveParameter] string $password,
        string $email,
        bool $passwordIsHashed,
        ?int $initialSiteId,
    ): string;

    /** @return array{result: 'created'|'login-exists'|'email-exists'|'login-is-email'|'email-is-login', token?: string} */
    public function invite(
        string $login,
        string $email,
        int $initialSiteId,
        int $expiryDays,
        string $inviter,
    ): array;

    /** @return array{result: 'updated'|'not-pending'|'denied', email?: string, token?: string} */
    public function renewInvitation(
        string $login,
        int $expiryDays,
        bool $linkOnly,
        string $requester,
        bool $requesterIsSuperuser,
    ): array;

    /** @return 'updated'|'not-found'|'only-superuser' */
    public function setSuperuser(string $login, bool $enabled): string;

    public function deleteSessions(string $login): bool;

    /** @return array{result: 'updated'|'not-found'|'email-exists'|'email-is-login', emailChanged?: bool, passwordChanged?: bool, email?: string, inviteToken?: string} */
    public function update(
        string $login,
        #[\SensitiveParameter] ?string $password,
        ?string $email,
        bool $passwordIsHashed,
        int $inviteExpiryDays,
    ): array;

    /** @return 'deleted'|'not-found'|'denied'|'only-superuser' */
    public function delete(string $login, string $requester, bool $requesterIsSuperuser): string;
}
