<?php

declare(strict_types=1);

namespace App\Matomo\Feedback;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseFeedbackStore implements FeedbackStore
{
    public function __construct(private ConnectionInterface $connection) {}

    public function emailForLogin(string $login): string
    {
        $email = $this->connection->table('user')->where('login', $login)->value('email');

        return is_string($email) ? $email : '';
    }

    public function setNextReminder(string $login, string $date): void
    {
        $this->connection->table('plugin_setting')->updateOrInsert(
            [
                'plugin_name' => 'Feedback',
                'user_login' => $login,
                'setting_name' => 'nextFeedbackReminder',
            ],
            [
                'setting_value' => $date,
                'json_encoded' => 0,
            ],
        );
    }
}
