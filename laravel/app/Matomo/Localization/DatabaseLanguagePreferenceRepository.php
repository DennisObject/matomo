<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

use Exception;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseLanguagePreferenceRepository implements LanguagePreferenceRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function forLogin(string $login): ?string
    {
        try {
            $language = $this->connection
                ->table('user_language')
                ->where('login', $login)
                ->value('language');
        } catch (Exception) {
            return null;
        }

        return is_string($language) ? $language : null;
    }
}
