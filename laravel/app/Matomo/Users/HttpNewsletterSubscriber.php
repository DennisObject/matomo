<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory;
use Throwable;

final readonly class HttpNewsletterSubscriber implements NewsletterSubscriber
{
    public function __construct(
        private Factory $http,
        private ConnectionInterface $connection,
        private string $endpoint,
        private bool $internetEnabled,
        private string $language,
    ) {}

    public function subscribe(string $login, string $email): bool
    {
        if (! $this->internetEnabled || $this->endpoint === '') {
            return false;
        }

        try {
            $response = $this->http->timeout(2)->get($this->endpoint, [
                'email' => $email,
                'piwikorg' => 1,
                'piwikpro' => 0,
                'language' => $this->language,
            ]);
            if (! $response->successful()) {
                return false;
            }

            $this->connection->table('option')->updateOrInsert(
                ['option_name' => 'UsersManager.newsletterSignup.'.$login],
                ['option_value' => '1', 'autoload' => 0],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
