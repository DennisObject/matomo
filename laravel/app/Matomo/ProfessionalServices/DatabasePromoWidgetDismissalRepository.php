<?php

declare(strict_types=1);

namespace App\Matomo\ProfessionalServices;

use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabasePromoWidgetDismissalRepository implements PromoWidgetDismissalRepository
{
    private const string PLUGIN = 'ProfessionalServices';

    private const string SETTING = 'dismissedWidgets';

    public function __construct(private ConnectionInterface $connection) {}

    public function dismiss(string $login, string $widgetName, int $timestamp): void
    {
        $this->connection->transaction(function () use ($login, $widgetName, $timestamp): void {
            $setting = $this->connection
                ->table('plugin_setting')
                ->select(['setting_value', 'json_encoded'])
                ->where('plugin_name', self::PLUGIN)
                ->where('user_login', $login)
                ->where('setting_name', self::SETTING)
                ->lockForUpdate()
                ->first();
            $dismissed = $this->decodedWidgets($setting);
            $dismissed[$widgetName] = $timestamp;

            $this->connection->table('plugin_setting')->updateOrInsert(
                [
                    'plugin_name' => self::PLUGIN,
                    'user_login' => $login,
                    'setting_name' => self::SETTING,
                ],
                [
                    'setting_value' => json_encode($dismissed, JSON_THROW_ON_ERROR),
                    'json_encoded' => 1,
                ],
            );
        });
    }

    /** @return array<string, int> */
    private function decodedWidgets(?object $setting): array
    {
        if ($setting === null || (int) ($setting->json_encoded ?? 0) !== 1) {
            return [];
        }

        $value = $setting->setting_value ?? null;

        if (! is_string($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $widgets = [];

        foreach ($decoded as $name => $timestamp) {
            if (is_string($name) && (is_int($timestamp) || is_string($timestamp))) {
                $widgets[$name] = (int) $timestamp;
            }
        }

        return $widgets;
    }
}
