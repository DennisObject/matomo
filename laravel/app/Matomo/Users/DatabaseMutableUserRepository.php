<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseMutableUserRepository implements MutableUserRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        #[\SensitiveParameter]
        private string $salt,
    ) {}

    public function create(
        string $login,
        string $password,
        string $email,
        bool $passwordIsHashed,
        ?int $initialSiteId,
    ): string {
        return $this->connection->transaction(function () use (
            $login,
            $password,
            $email,
            $passwordIsHashed,
            $initialSiteId,
        ): string {
            $conflict = $this->conflict($login, $email);
            if ($conflict !== null) {
                return $conflict;
            }

            $now = CarbonImmutable::now()->toDateTimeString();
            $passwordHash = $passwordIsHashed ? $password : md5($password);
            $this->connection->table('user')->insert([
                'login' => $login,
                'password' => password_hash($passwordHash, PASSWORD_DEFAULT),
                'email' => $email,
                'date_registered' => $now,
                'superuser_access' => 0,
                'ts_password_modified' => $now,
                'idchange_last_viewed' => null,
                'invited_by' => null,
            ]);
            if ($initialSiteId !== null) {
                $this->connection->table('access')->insert([
                    'login' => $login,
                    'idsite' => $initialSiteId,
                    'access' => 'view',
                ]);
            }

            return 'created';
        });
    }

    public function invite(
        string $login,
        string $email,
        int $initialSiteId,
        int $expiryDays,
        string $inviter,
    ): array {
        return $this->connection->transaction(function () use (
            $login,
            $email,
            $initialSiteId,
            $expiryDays,
            $inviter,
        ): array {
            $conflict = $this->conflict($login, $email);
            if ($conflict !== null) {
                return ['result' => $conflict];
            }

            $token = bin2hex(random_bytes(32));
            $now = CarbonImmutable::now();
            $this->connection->table('user')->insert([
                'login' => $login,
                'password' => '',
                'email' => $email,
                'date_registered' => $now->toDateTimeString(),
                'superuser_access' => 0,
                'ts_password_modified' => $now->toDateTimeString(),
                'idchange_last_viewed' => null,
                'invited_by' => $inviter,
                'invite_token' => hash('sha512', $token.$this->salt),
                'invite_link_token' => null,
                'invite_expired_at' => $now->addDays($expiryDays)->toDateTimeString(),
            ]);
            $this->connection->table('access')->insert([
                'login' => $login,
                'idsite' => $initialSiteId,
                'access' => 'view',
            ]);

            return ['result' => 'created', 'token' => $token];
        });
    }

    public function renewInvitation(
        string $login,
        int $expiryDays,
        bool $linkOnly,
        string $requester,
        bool $requesterIsSuperuser,
    ): array {
        return $this->connection->transaction(function () use (
            $login,
            $expiryDays,
            $linkOnly,
            $requester,
            $requesterIsSuperuser,
        ): array {
            $user = $this->connection->table('user')
                ->where(static function ($query) use ($login): void {
                    $query->where('login', $login)->orWhere('email', $login);
                })
                ->whereNotNull('invite_token')
                ->lockForUpdate()
                ->first();
            if ($user === null || ! is_string($user->login ?? null) || ! is_string($user->email ?? null)) {
                return ['result' => 'not-pending'];
            }

            if (! $requesterIsSuperuser && ($user->invited_by ?? null) !== $requester) {
                return ['result' => 'denied'];
            }

            $token = bin2hex(random_bytes(32));
            $values = [
                $linkOnly ? 'invite_link_token' : 'invite_token' => hash('sha512', $token.$this->salt),
                'invite_expired_at' => CarbonImmutable::now()->addDays($expiryDays)->toDateTimeString(),
            ];
            if (! $linkOnly) {
                $values['invite_link_token'] = null;
            }

            $this->connection->table('user')->where('login', $user->login)->update($values);

            return ['result' => 'updated', 'email' => $user->email, 'token' => $token];
        });
    }

    public function setSuperuser(string $login, bool $enabled): string
    {
        return $this->connection->transaction(function () use ($login, $enabled): string {
            $user = $this->connection->table('user')->where('login', $login)->lockForUpdate()->first();
            if ($user === null) {
                return 'not-found';
            }

            if (! $enabled && (int) ($user->superuser_access ?? 0) === 1) {
                $superusers = $this->connection->table('user')
                    ->where('superuser_access', 1)->lockForUpdate()->count();
                if ($superusers === 1) {
                    return 'only-superuser';
                }
            }

            $this->connection->table('access')->where('login', $login)->delete();
            $this->connection->table('user')->where('login', $login)->update([
                'superuser_access' => $enabled ? 1 : 0,
            ]);

            return 'updated';
        });
    }

    public function deleteSessions(string $login): bool
    {
        if (! $this->connection->table('user')->where('login', $login)->exists()) {
            return false;
        }

        $serialized = strlen('user.name').':"user.name";s:'.strlen($login).':"'.$login.'"';
        $remainder = strlen($serialized) % 3;
        if ($remainder === 1) {
            $serialized = 's:'.$serialized;
        } elseif ($remainder === 2) {
            $serialized = ':'.$serialized;
        }

        $patterns = [
            '%'.base64_encode($serialized).'%',
            '%'.base64_encode(substr($serialized, 1).';').'%',
            '%'.base64_encode(substr($serialized, 2).';s').'%',
            '%'.base64_encode(substr($serialized, 2).';i').'%',
        ];
        $query = $this->connection->table('session');
        $query->where(static function ($builder) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $builder->orWhere('data', 'like', $pattern);
            }
        })->delete();

        return true;
    }

    public function update(
        string $login,
        ?string $password,
        ?string $email,
        bool $passwordIsHashed,
        int $inviteExpiryDays,
    ): array {
        return $this->connection->transaction(function () use (
            $login,
            $password,
            $email,
            $passwordIsHashed,
            $inviteExpiryDays,
        ): array {
            $user = $this->connection->table('user')->where('login', $login)->lockForUpdate()->first();
            if ($user === null || ! is_string($user->email ?? null)) {
                return ['result' => 'not-found'];
            }

            $newEmail = $email ?? $user->email;
            $emailChanged = strcasecmp($newEmail, $user->email) !== 0;
            if ($emailChanged && $this->connection->table('user')
                ->where('email', $newEmail)->where('login', '!=', $login)->exists()) {
                return ['result' => 'email-exists'];
            }

            if ($emailChanged && $this->connection->table('user')
                ->where('login', $newEmail)->where('login', '!=', $login)->exists()) {
                return ['result' => 'email-is-login'];
            }

            $values = ['email' => $newEmail];
            if ($password !== null) {
                $info = password_get_info($password);
                $values['password'] = ($passwordIsHashed && ($info['algo'] ?? null) !== null)
                    ? $password
                    : password_hash($passwordIsHashed ? $password : md5($password), PASSWORD_DEFAULT);
                $values['ts_password_modified'] = CarbonImmutable::now()->toDateTimeString();
            }

            $token = null;
            if ($emailChanged && ($user->invite_token ?? null) !== null) {
                $token = bin2hex(random_bytes(32));
                $values['invite_token'] = hash('sha512', $token.$this->salt);
                $values['invite_link_token'] = null;
                $values['invite_expired_at'] = CarbonImmutable::now()
                    ->addDays($inviteExpiryDays)->toDateTimeString();
            }

            $this->connection->table('user')->where('login', $login)->update($values);

            $result = [
                'result' => 'updated',
                'emailChanged' => $emailChanged,
                'passwordChanged' => $password !== null,
                'email' => $newEmail,
            ];
            if ($token !== null) {
                $result['inviteToken'] = $token;
            }

            return $result;
        });
    }

    public function delete(string $login, string $requester, bool $requesterIsSuperuser): string
    {
        return $this->connection->transaction(function () use ($login, $requester, $requesterIsSuperuser): string {
            $user = $this->connection->table('user')->where('login', $login)->lockForUpdate()->first();
            if ($user === null) {
                return 'not-found';
            }

            if (! $requesterIsSuperuser
                && (($user->invited_by ?? null) !== $requester || ($user->invite_token ?? null) === null)) {
                return 'denied';
            }

            if ((int) ($user->superuser_access ?? 0) === 1
                && $this->connection->table('user')->where('superuser_access', 1)->lockForUpdate()->count() === 1) {
                return 'only-superuser';
            }

            $this->connection->table('user_token_auth')->where('login', $login)->delete();
            $this->connection->table('plugin_setting')->where('user_login', $login)->delete();
            $this->connection->table('access')->where('login', $login)->delete();
            $this->connection->table('option')->where('option_name', 'like', 'UsersManager.%.'.$login)->delete();
            $this->connection->table('option')->whereIn('option_name', [
                'Feedback.nextFeedbackReminder.'.$login,
                $login.'_MobileMessagingSettings',
                $login.'_defaultReport',
                $login.'_defaultReportDate',
                $login.'_isLDAPUser',
                $login.'_hideSegmentDefinitionChangeMessage',
            ])->delete();
            $this->connection->table('option')
                ->where('option_name', 'like', 'ProfessionalServices.DismissedWidget.%.'.$login)->delete();
            $this->connection->table('user')->where('login', $login)->delete();

            return 'deleted';
        });
    }

    public function createToken(
        string $loginOrEmail,
        string $description,
        ?string $expiresAt,
        bool $secureOnly,
    ): array {
        return $this->connection->transaction(function () use (
            $loginOrEmail,
            $description,
            $expiresAt,
            $secureOnly,
        ): array {
            $user = $this->connection->table('user')
                ->where('login', $loginOrEmail)
                ->orWhere('email', $loginOrEmail)
                ->lockForUpdate()
                ->first();
            if ($user === null || ! is_string($user->login ?? null)) {
                return ['result' => 'not-found'];
            }

            $token = bin2hex(random_bytes(16));
            $this->connection->table('user_token_auth')->insert([
                'login' => $user->login,
                'description' => $description,
                'password' => hash('sha512', $token.$this->salt),
                'date_created' => CarbonImmutable::now()->toDateTimeString(),
                'date_expired' => $expiresAt,
                'system_token' => 0,
                'hash_algo' => 'sha512',
                'secure_only' => $secureOnly ? 1 : 0,
            ]);

            return ['result' => 'created', 'token' => $token];
        });
    }

    /** @return 'login-exists'|'email-exists'|'login-is-email'|'email-is-login'|null */
    private function conflict(string $login, string $email): ?string
    {
        if ($this->connection->table('user')->where('login', $login)->lockForUpdate()->exists()) {
            return 'login-exists';
        }

        if ($this->connection->table('user')->where('email', $login)->lockForUpdate()->exists()) {
            return 'login-is-email';
        }

        if ($this->connection->table('user')->where('email', $email)->lockForUpdate()->exists()) {
            return 'email-exists';
        }

        if (strcasecmp($login, $email) !== 0
            && $this->connection->table('user')->where('login', $email)->lockForUpdate()->exists()) {
            return 'email-is-login';
        }

        return null;
    }
}
