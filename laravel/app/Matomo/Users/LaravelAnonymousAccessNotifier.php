<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Mail\Message;

final readonly class LaravelAnonymousAccessNotifier implements AnonymousAccessNotifier
{
    public function __construct(
        private ConnectionInterface $connection,
        private Mailer $mailer,
    ) {}

    public function notify(array $siteIds): void
    {
        $siteNames = array_values(array_filter(
            $this->connection->table('site')->whereIn('idsite', $siteIds)->pluck('name')->all(),
            is_string(...),
        ));
        $recipients = $this->connection->table('user')
            ->select(['login', 'email'])
            ->where('superuser_access', 1)
            ->get();
        foreach ($recipients as $recipient) {
            if (! is_string($recipient->email ?? null) || $recipient->email === '') {
                continue;
            }

            $login = is_string($recipient->login ?? null) ? $recipient->login : '';
            $body = 'Anonymous view access was enabled for: '.implode(', ', $siteNames);
            $this->mailer->raw($body, static function (Message $message) use ($recipient, $login): void {
                $message->to($recipient->email, $login);
                $message->subject('Anonymous access enabled');
            });
        }
    }
}
