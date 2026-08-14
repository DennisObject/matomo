<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseVersionAccessAuthorizer implements VersionAccessAuthorizer
{
    public function __construct(
        private ConnectionInterface $connection,
        #[\SensitiveParameter]
        private string $salt,
        private bool $onlyAllowSecureTokens,
        private ?DatabaseSessionAuthenticator $sessions = null,
    ) {}

    public function hasSomeViewAccess(ApiAuthentication $authentication): bool
    {
        $user = $this->sessions?->authenticate($authentication);

        if ($user === null) {
            $user = in_array($authentication->token, [null, '', 'anonymous'], true)
            ? $this->findUser('anonymous')
            : $this->authenticateToken($authentication->token, $authentication->tokenIsSecure);
        }

        if ($user === null) {
            return false;
        }

        if ($user['isSuperUser']) {
            return true;
        }

        return $this->connection
            ->table('access as access')
            ->join('site as site', 'access.idsite', '=', 'site.idsite')
            ->where('access.login', $user['login'])
            ->whereIn('access.access', ['view', 'write', 'admin'])
            ->exists();
    }

    /**
     * @return array{login: string, isSuperUser: bool}|null
     */
    private function authenticateToken(#[\SensitiveParameter] string $token, bool $tokenIsSecure): ?array
    {
        if ($this->onlyAllowSecureTokens && ! $tokenIsSecure) {
            return null;
        }

        $now = CarbonImmutable::now('UTC')->toDateTimeString();
        $query = $this->connection
            ->table('user_token_auth as token')
            ->join('user as user', 'token.login', '=', 'user.login')
            ->select(['token.idusertokenauth', 'user.login', 'user.superuser_access'])
            ->where('token.password', hash('sha512', $token.$this->salt))
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('token.date_expired')
                    ->orWhere('token.date_expired', '>', $now);
            });

        if (! $tokenIsSecure) {
            $query->where('token.secure_only', 0);
        }

        $record = $query->first();
        $user = $this->normalizeUser($record);

        if ($record instanceof stdClass && $user !== null) {
            $tokenId = $record->idusertokenauth ?? null;

            if (is_int($tokenId) || is_string($tokenId)) {
                $this->connection
                    ->table('user_token_auth')
                    ->where('idusertokenauth', $tokenId)
                    ->update(['last_used' => $now]);
            }
        }

        return $user;
    }

    /**
     * @return array{login: string, isSuperUser: bool}|null
     */
    private function findUser(string $login): ?array
    {
        return $this->normalizeUser(
            $this->connection
                ->table('user')
                ->select(['login', 'superuser_access'])
                ->where('login', $login)
                ->first(),
        );
    }

    /**
     * @return array{login: string, isSuperUser: bool}|null
     */
    private function normalizeUser(?object $record): ?array
    {
        if (! $record instanceof stdClass) {
            return null;
        }

        $login = $record->login ?? null;
        $superUserAccess = $record->superuser_access ?? null;

        if (! is_string($login) || (! is_int($superUserAccess) && ! is_string($superUserAccess))) {
            return null;
        }

        return [
            'login' => $login,
            'isSuperUser' => (int) $superUserAccess === 1,
        ];
    }
}
