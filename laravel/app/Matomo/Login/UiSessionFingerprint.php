<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

final readonly class UiSessionFingerprint
{
    public function __construct(
        private int $sessionLifetime = 1_209_600,
        private int $idleTimeout = 3_600,
        #[\SensitiveParameter]
        private string $salt = '',
    ) {}

    public function initialize(
        Session $session,
        string $login,
        bool $remembered,
        #[\SensitiveParameter]
        ?string $tokenAuth = null,
    ): void {
        $now = CarbonImmutable::now('UTC')->getTimestamp();

        $session->put('matomo.login', $login);
        $session->put('user.name', $login);
        $session->put('user.token_auth_temp', $tokenAuth ?? $this->randomToken());
        $session->put('twofactorauth.verified', 0);
        $session->forget('twofactorauth.verified_user');
        $session->put('session.info', [
            'ts' => $now,
            'remembered' => $remembered,
            'expiration' => $now + $this->duration($remembered),
        ]);
    }

    public function maintain(Session $session): bool
    {
        $login = $session->get('matomo.login');
        if (! is_string($login) || $login === '') {
            return true;
        }

        $info = $session->get('session.info');
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $remembered = is_array($info) && ! empty($info['remembered']);
        $expiration = is_array($info) ? ($info['expiration'] ?? null) : null;

        if (is_numeric($expiration) && $now > (int) $expiration) {
            return false;
        }

        $session->put('session.info', [
            'ts' => is_array($info) && is_numeric($info['ts'] ?? null) ? (int) $info['ts'] : $now,
            'remembered' => $remembered,
            'expiration' => $now + $this->duration($remembered),
        ]);

        return true;
    }

    public function login(Session $session): ?string
    {
        $login = $session->get('matomo.login');

        return is_string($login) && $login !== '' ? $login : null;
    }

    public function remembered(Session $session): bool
    {
        $info = $session->get('session.info');

        return is_array($info) && ! empty($info['remembered']);
    }

    public function hasVerifiedTwoFactor(Session $session): bool
    {
        if ((int) $session->get('twofactorauth.verified') !== 1) {
            return false;
        }

        $verifiedUser = $session->get('twofactorauth.verified_user');
        $login = $session->get('user.name');
        if (is_string($verifiedUser) && $verifiedUser !== '') {
            return $verifiedUser === $login;
        }

        return true;
    }

    public function setTwoFactorVerified(Session $session, string $login): void
    {
        $session->put('twofactorauth.verified', 1);
        $session->put('twofactorauth.verified_user', $login);
    }

    public function applyCookieLifetime(bool $remembered): void
    {
        config([
            'session.expire_on_close' => ! $remembered,
            'session.lifetime' => max(1, (int) ceil($this->sessionLifetime / 60)),
        ]);
    }

    public static function wantsRememberMe(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'true', 'on'], true);
    }

    private function duration(bool $remembered): int
    {
        return $remembered ? $this->sessionLifetime : $this->idleTimeout;
    }

    private function randomToken(): string
    {
        return md5(bin2hex(random_bytes(16)).microtime(true).uniqid('', true).$this->salt);
    }
}
