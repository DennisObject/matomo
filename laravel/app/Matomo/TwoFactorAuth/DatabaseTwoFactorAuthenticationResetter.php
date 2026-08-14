<?php

declare(strict_types=1);

namespace App\Matomo\TwoFactorAuth;

use App\Matomo\Api\Events\TwoFactorAuthenticationDisabled;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class DatabaseTwoFactorAuthenticationResetter implements TwoFactorAuthenticationResetter
{
    public function __construct(
        private ConnectionInterface $connection,
        private Dispatcher $events,
    ) {}

    public function reset(string $login): void
    {
        if (strtolower($login) === 'anonymous') {
            throw new InvalidArgumentException('Anonymous cannot use two-factor authentication');
        }

        $this->connection->transaction(function () use ($login): void {
            $this->connection->table('user')->where('login', $login)->update([
                'twofactor_secret' => '',
            ]);
            $this->connection
                ->table('twofactor_recovery_code')
                ->where('login', $login)
                ->delete();
        });
        $this->events->dispatch(new TwoFactorAuthenticationDisabled($login));
    }
}
