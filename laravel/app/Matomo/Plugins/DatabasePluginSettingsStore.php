<?php

declare(strict_types=1);

namespace App\Matomo\Plugins;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabasePluginSettingsStore implements PluginSettingsStore
{
    public function __construct(private ConnectionInterface $connection) {}

    public function values(string $pluginName, string $login): array
    {
        $rows = $this->connection->table('plugin_setting')
            ->where('plugin_name', $pluginName)->where('user_login', $login)
            ->get(['setting_name', 'setting_value', 'json_encoded']);
        $values = [];
        foreach ($rows as $row) {
            $name = $row->setting_name ?? null;
            if (! is_string($name)) {
                continue;
            }

            $value = $row->setting_value ?? null;
            $values[$name] = (int) ($row->json_encoded ?? 0) === 1 && is_string($value)
                ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
                : $value;
        }

        return $values;
    }

    public function replace(string $pluginName, string $login, array $values): void
    {
        $this->connection->transaction(function () use ($pluginName, $login, $values): void {
            $this->connection->table('plugin_setting')
                ->where('plugin_name', $pluginName)->where('user_login', $login)->delete();
            $rows = [];
            foreach ($values as $name => $value) {
                $json = is_array($value) || is_object($value);
                $rows[] = [
                    'plugin_name' => $pluginName, 'user_login' => $login, 'setting_name' => $name,
                    'setting_value' => $json ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
                    'json_encoded' => $json ? 1 : 0,
                ];
            }

            if ($rows !== []) {
                $this->connection->table('plugin_setting')->insert($rows);
            }
        });
    }
}
