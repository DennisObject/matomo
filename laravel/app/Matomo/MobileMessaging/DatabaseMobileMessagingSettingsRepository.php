<?php

declare(strict_types=1);

namespace App\Matomo\MobileMessaging;

use App\Matomo\Options\MutableOptionRepository;
use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabaseMobileMessagingSettingsRepository implements MobileMessagingSettingsRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private MutableOptionRepository $options,
    ) {}

    public function read(string $login): array
    {
        $rows = $this->connection->table('plugin_setting')
            ->select(['setting_name', 'setting_value', 'json_encoded'])
            ->where('plugin_name', 'MobileMessaging')
            ->where('user_login', $login)
            ->get();
        $settings = [];

        foreach ($rows as $row) {
            $name = $row->setting_name ?? null;
            $value = $row->setting_value ?? null;
            if (! is_string($name) || ! is_string($value)) {
                continue;
            }

            if ((int) ($row->json_encoded ?? 0) === 1) {
                try {
                    $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }
            }

            $settings[$name] = $value;
        }

        if ($settings !== []) {
            return $settings;
        }

        $legacy = $this->options->value($login.'_MobileMessagingSettings');
        if ($legacy === null && $login === '') {
            $legacy = $this->options->value('_MobileMessagingSettings');
        }

        try {
            $decoded = json_decode($legacy ?? '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function save(string $login, #[\SensitiveParameter] array $settings): void
    {
        $this->connection->transaction(function () use ($login, $settings): void {
            $this->connection->table('plugin_setting')
                ->where('plugin_name', 'MobileMessaging')->where('user_login', $login)->delete();
            foreach ($settings as $name => $value) {
                $json = ! is_string($value);
                $this->connection->table('plugin_setting')->insert([
                    'plugin_name' => 'MobileMessaging', 'user_login' => $login, 'setting_name' => $name,
                    'setting_value' => $json ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
                    'json_encoded' => $json ? 1 : 0,
                ]);
            }
        });
    }
}
