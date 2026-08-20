<?php

declare(strict_types=1);

namespace App\Matomo\Users;

use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabaseUserPreferenceRepository implements UserPreferenceRepository
{
    private const string PLUGIN = 'UsersManager';

    public function __construct(private ConnectionInterface $connection) {}

    public function canonicalLogin(string $login): ?string
    {
        $value = $this->connection->table('user')
            ->whereRaw('LOWER(login) = LOWER(?)', [$login])
            ->value('login');

        return is_string($value) ? $value : null;
    }

    public function get(string $login, string $name): array
    {
        $record = $this->connection->table('plugin_setting')
            ->select(['setting_value', 'json_encoded'])
            ->where('plugin_name', self::PLUGIN)
            ->where('user_login', $login)
            ->where('setting_name', $name)
            ->first();
        if ($record === null) {
            return ['found' => false, 'value' => null];
        }

        return [
            'found' => true,
            'value' => $this->decode($record->setting_value ?? '', (bool) ($record->json_encoded ?? false)),
        ];
    }

    public function set(string $login, string $name, mixed $value): void
    {
        $this->connection->transaction(function () use ($login, $name, $value): void {
            $query = $this->connection->table('plugin_setting')
                ->where('plugin_name', self::PLUGIN)
                ->where('user_login', $login)
                ->where('setting_name', $name);
            $query->delete();
            if ($value === null) {
                return;
            }

            [$stored, $json] = $this->encode($value);
            $this->connection->table('plugin_setting')->insert([
                'plugin_name' => self::PLUGIN,
                'user_login' => $login,
                'setting_name' => $name,
                'setting_value' => $stored,
                'json_encoded' => (int) $json,
            ]);
        });
    }

    public function forAllUsers(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $result = [];
        $rows = $this->connection->table('plugin_setting')
            ->select(['user_login', 'setting_name', 'setting_value', 'json_encoded'])
            ->where('plugin_name', self::PLUGIN)
            ->whereIn('setting_name', $names)
            ->where('user_login', '<>', '')
            ->get();
        foreach ($rows as $row) {
            if (! is_string($row->user_login ?? null) || ! is_string($row->setting_name ?? null)) {
                continue;
            }

            $result[$row->user_login][$row->setting_name] = $this->decode(
                $row->setting_value ?? '',
                (bool) ($row->json_encoded ?? false),
            );
        }

        return $result;
    }

    /** @return array{string, bool} */
    private function encode(mixed $value): array
    {
        if (is_array($value) || is_object($value)) {
            return [json_encode($value, JSON_THROW_ON_ERROR), true];
        }

        return [(string) (is_bool($value) ? (int) $value : $value), false];
    }

    private function decode(mixed $value, bool $json): mixed
    {
        if (! $json) {
            return $value;
        }

        try {
            return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
