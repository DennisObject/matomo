<?php

declare(strict_types=1);

namespace App\Matomo\Authentication;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;
use Throwable;

final readonly class DatabaseSessionAuthenticator
{
    public function __construct(
        private ConnectionInterface $connection,
        #[\SensitiveParameter]
        private string $salt,
        private int $sessionLifetime,
        private int $idleTimeout,
    ) {}

    /**
     * @return array{login: string, isSuperUser: bool}|null
     */
    public function authenticate(ApiAuthentication $authentication): ?array
    {
        if (! $authentication->forceSession || $authentication->token === null) {
            return null;
        }

        $sessionId = $authentication->sessionId;

        if ($sessionId === null || preg_match('/^[a-zA-Z0-9,-]{1,128}$/D', $sessionId) !== 1) {
            return null;
        }

        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $hashedSessionId = hash('sha512', $sessionId.$this->salt);
        $record = $this->connection
            ->table('session')
            ->select(['data', 'modified', 'lifetime'])
            ->where('id', $hashedSessionId)
            ->whereRaw('(modified + lifetime) >= ?', [$now])
            ->first();

        if (! $record instanceof stdClass || ! is_string($record->data ?? null)) {
            return null;
        }

        $session = $this->decode($record->data);
        $login = $session['user.name'] ?? null;
        $sessionToken = $session['user.token_auth_temp'] ?? null;
        $sessionInfo = $session['session.info'] ?? null;

        if (
            ! is_string($login)
            || ! is_string($sessionToken)
            || ! is_array($sessionInfo)
            || ! hash_equals($sessionToken, $authentication->token)
        ) {
            return null;
        }

        $startedAt = $this->integer($sessionInfo['ts'] ?? null);
        $expiresAt = $this->integer($sessionInfo['expiration'] ?? null);

        if ($startedAt === null || $expiresAt === null || $now > $expiresAt) {
            return null;
        }

        $user = $this->connection
            ->table('user')
            ->select(['login', 'superuser_access', 'ts_password_modified'])
            ->where('login', $login)
            ->first();

        if (! $user instanceof stdClass || $user->login !== $login) {
            return null;
        }

        $rawPasswordChangedAt = $user->ts_password_modified ?? null;
        $passwordChangedAt = $this->passwordChangedAt($rawPasswordChangedAt);

        if ($rawPasswordChangedAt !== null && $rawPasswordChangedAt !== '' && $passwordChangedAt === null) {
            return null;
        }

        if ($passwordChangedAt !== null && $startedAt < $passwordChangedAt) {
            return null;
        }

        $this->refresh($hashedSessionId, $session, $sessionInfo, $now);

        return [
            'login' => $login,
            'isSuperUser' => (int) ($user->superuser_access ?? 0) === 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $data): array
    {
        try {
            $session = @unserialize($data, ['allowed_classes' => false]);
        } catch (Throwable) {
            return [];
        }

        return is_array($session) ? $session : [];
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/D', $value) === 1
            ? (int) $value
            : null;
    }

    private function passwordChangedAt(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC');

            if ($date === null || $date->format('Y-m-d H:i:s') !== $value) {
                return null;
            }

            return $date->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<array-key, mixed>  $sessionInfo
     */
    private function refresh(string $id, array $session, array $sessionInfo, int $now): void
    {
        if (! $this->containsOnlySerializableValues($session)) {
            return;
        }

        $remembered = ! empty($sessionInfo['remembered']);
        $sessionInfo['expiration'] = $now + ($remembered ? $this->sessionLifetime : $this->idleTimeout);
        $session['session.info'] = $sessionInfo;

        $this->connection
            ->table('session')
            ->where('id', $id)
            ->update([
                'modified' => $now,
                'lifetime' => $this->sessionLifetime,
                'data' => serialize($session),
            ]);
    }

    private function containsOnlySerializableValues(mixed $value): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->containsOnlySerializableValues($item)) {
                return false;
            }
        }

        return true;
    }
}
