<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Carbon\CarbonImmutable;

final class UserPresenter
{
    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public function present(
        array $user,
        string $currentLogin,
        bool $superuser,
        bool $twoFactorEnabled,
    ): array {
        foreach ([
            'token_auth',
            'password',
            'ts_password_modified',
            'idchange_last_viewed',
            'ts_changes_shown',
            'invite_token',
            'invite_link_token',
            'ts_inactivity_notified',
        ] as $sensitive) {
            unset($user[$sensitive]);
        }

        $lastSeen = $user['ts_last_seen'] ?? null;
        unset($user['ts_last_seen']);
        if (is_string($lastSeen) && $lastSeen !== '') {
            $user['last_seen'] = $lastSeen;
            $user['last_seen_ago'] = CarbonImmutable::parse($lastSeen, 'UTC')->diffForHumans();
        }

        $user['invite_status'] = $this->inviteStatus($user['invite_expired_at'] ?? null);
        if ($superuser) {
            $user['uses_2fa'] = $twoFactorEnabled && ! empty($user['twofactor_secret']);
            unset($user['twofactor_secret']);

            return $user;
        }

        $visible = [
            'login' => $user['login'] ?? '',
            'invite_status' => $user['invite_status'],
        ];
        if (($user['login'] ?? null) === $currentLogin || ! empty($user['superuser_access'])) {
            $visible['email'] = $user['email'] ?? '';
        }

        foreach (['role', 'capabilities', 'superuser_access', 'last_seen', 'invited_by'] as $key) {
            if (array_key_exists($key, $user)) {
                $visible[$key] = $user[$key];
            }
        }

        return $visible;
    }

    private function inviteStatus(mixed $expiresAt): int|string
    {
        if (! is_string($expiresAt) || $expiresAt === '') {
            return 'active';
        }

        try {
            $expiry = CarbonImmutable::parse($expiresAt, 'UTC');
        } catch (\Throwable) {
            return 'expired';
        }

        if ($expiry->isPast()) {
            return 'expired';
        }

        return (int) floor(CarbonImmutable::now('UTC')->diffInSeconds($expiry) / 86400);
    }
}
