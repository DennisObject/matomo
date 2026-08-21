<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Users\UserIdentityRepository;

final readonly class PasswordLoginAuthenticator
{
    public function __construct(
        private UserIdentityRepository $identities,
        private PasswordConfirmationVerifier $passwords,
        private LoginAttemptGuard $attempts,
    ) {}

    public function attempt(
        string $loginOrEmail,
        #[\SensitiveParameter]
        string $password,
        string $ipAddress,
        bool $passwordIsHashed = false,
        bool $rejectSuperUser = false,
    ): PasswordLoginResult {
        $login = $this->normalizedLogin($loginOrEmail);
        $status = $this->attempts->status($ipAddress, $login);

        if ($status === LoginAttemptStatus::IpBlocked) {
            return PasswordLoginResult::failed('You cannot use this Matomo as this IP is blocked.', 403);
        }

        if ($status === LoginAttemptStatus::UserBlocked) {
            return PasswordLoginResult::failed('Login not allowed because this user is blocked.', 403);
        }

        $passwordMatches = $password !== '' && ($passwordIsHashed
            ? $this->passwords->isCorrectHash($login, $password)
            : $this->passwords->isCorrect($login, $password));

        if ($login === '' || strtolower($login) === 'anonymous' || ! $passwordMatches) {
            $this->attempts->recordFailure($ipAddress, $login);

            return PasswordLoginResult::failed('The username and/or password you used are incorrect.', 403);
        }

        if ($rejectSuperUser && $this->identities->hasSuperUserAccess($login)) {
            return PasswordLoginResult::failed(
                "A user with superuser access cannot be authenticated using the 'logme' mechanism.",
                403,
            );
        }

        return PasswordLoginResult::success($login);
    }

    private function normalizedLogin(string $loginOrEmail): string
    {
        $loginOrEmail = trim($loginOrEmail);
        if ($loginOrEmail === '' || ! str_contains($loginOrEmail, '@')) {
            return $loginOrEmail;
        }

        return $this->identities->loginForEmail($loginOrEmail) ?? $loginOrEmail;
    }
}
