<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

use App\Matomo\Authentication\Events\UserSiteAccessLoaded;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseApiAccessAuthorizer implements ApiAccessAuthorizer
{
    public function __construct(
        private ConnectionInterface $connection,
        #[\SensitiveParameter]
        private string $salt,
        private bool $onlyAllowSecureTokens,
        private ?DatabaseSessionAuthenticator $sessions = null,
        private ?Dispatcher $events = null,
    ) {}

    public function hasSomeViewAccess(ApiAuthentication $authentication): bool
    {
        $user = $this->authenticatedUser($authentication);

        if ($user === null) {
            return false;
        }

        if ($user['isSuperUser']) {
            return true;
        }

        $siteIdsByAccess = $this->siteIdsByAccess($user['login']);

        return ($siteIdsByAccess['view'] ?? []) !== []
            || ($siteIdsByAccess['write'] ?? []) !== []
            || ($siteIdsByAccess['admin'] ?? []) !== [];
    }

    public function hasSuperUserAccess(ApiAuthentication $authentication): bool
    {
        return $this->authenticatedUser($authentication)['isSuperUser'] ?? false;
    }

    public function siteIdsWithAdminAccess(ApiAuthentication $authentication): array
    {
        $user = $this->authenticatedUser($authentication);

        if ($user === null) {
            return [];
        }

        if ($user['isSuperUser']) {
            return $this->integerList(
                $this->connection->table('site')->pluck('idsite')->all(),
            );
        }

        return $this->integerList($this->siteIdsByAccess($user['login'])['admin'] ?? []);
    }

    /**
     * @return array{login: string, isSuperUser: bool}|null
     */
    private function authenticatedUser(ApiAuthentication $authentication): ?array
    {
        $user = $this->sessions?->authenticate($authentication);

        if ($user !== null) {
            return $user;
        }

        return in_array($authentication->token, [null, '', 'anonymous'], true)
            ? $this->findUser('anonymous')
            : $this->authenticateToken($authentication->token, $authentication->tokenIsSecure);
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

    /**
     * @return array<string, list<int>>
     */
    private function siteIdsByAccess(string $login): array
    {
        $siteIdsByAccess = [
            'view' => [],
            'write' => [],
            'admin' => [],
        ];
        $records = $this->connection
            ->table('access as access')
            ->join('site as site', 'access.idsite', '=', 'site.idsite')
            ->select(['access.access', 'site.idsite'])
            ->where('access.login', $login)
            ->get();

        foreach ($records as $record) {
            $access = $record->access ?? null;
            $idSite = $record->idsite ?? null;

            if (is_string($access) && isset($siteIdsByAccess[$access]) && (is_int($idSite) || is_string($idSite))) {
                $siteIdsByAccess[$access][] = (int) $idSite;
            }
        }

        $event = new UserSiteAccessLoaded($login, $siteIdsByAccess);
        $this->events?->dispatch($event);

        return $event->siteIdsByAccess;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<int>
     */
    private function integerList(array $values): array
    {
        $integers = [];

        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $integers[] = (int) $value;
            }
        }

        return array_values(array_unique($integers));
    }
}
