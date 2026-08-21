<?php

declare(strict_types=1);

namespace App\Matomo\TwoFactorAuth;

use App\Matomo\Options\MutableOptionRepository;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseTwoFactorCodeVerifier implements TwoFactorCodeVerifier
{
    private const string USED_CODE_PREFIX = 'twofa_codes_used_';

    private const int BLOCK_SECONDS = 600;

    public function __construct(
        private ConnectionInterface $connection,
        private MutableOptionRepository $options,
        private TimeBasedOneTimePassword $passwords,
        #[\SensitiveParameter]
        private string $salt,
    ) {}

    public function verify(
        string $login,
        #[\SensitiveParameter]
        string $authCode,
    ): bool {
        if ($login === '' || strtolower($login) === 'anonymous') {
            return false;
        }

        $authCode = strtoupper(str_replace('-', '', trim($authCode)));
        if ($authCode === '') {
            return false;
        }

        $secret = $this->connection->table('user')->where('login', $login)->value('twofactor_secret');
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        if ($this->wasUsedRecently($login, $authCode) || ! $this->markUsed($login, $authCode)) {
            return false;
        }

        if ($this->passwords->matches($secret, $authCode)) {
            return true;
        }

        return $this->consumeRecoveryCode($login, $authCode);
    }

    private function wasUsedRecently(string $login, string $authCode): bool
    {
        $usedAt = $this->options->value($this->usedKey($login, $authCode));

        return is_numeric($usedAt) && (int) $usedAt >= time() - self::BLOCK_SECONDS;
    }

    private function markUsed(string $login, string $authCode): bool
    {
        $key = $this->usedKey($login, $authCode);
        $usedAt = $this->options->value($key);
        if (is_numeric($usedAt) && (int) $usedAt > time() - self::BLOCK_SECONDS) {
            return false;
        }

        $this->options->set($key, (string) time());

        return true;
    }

    private function consumeRecoveryCode(string $login, string $authCode): bool
    {
        return $this->connection->transaction(function () use ($login, $authCode): bool {
            $existing = $this->connection
                ->table('twofactor_recovery_code')
                ->where('login', $login)
                ->where('recovery_code', $authCode)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return false;
            }

            $this->connection
                ->table('twofactor_recovery_code')
                ->where('login', $login)
                ->where('recovery_code', $authCode)
                ->delete();

            return true;
        });
    }

    private function usedKey(string $login, string $authCode): string
    {
        return self::USED_CODE_PREFIX.md5($login.$authCode.$this->salt);
    }
}
