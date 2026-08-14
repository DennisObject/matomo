<?php

declare(strict_types=1);

namespace App\Matomo\Settings;

use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabasePolicySettingRepository implements PolicySettingRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function systemBoolean(string $pluginName, string $settingName): ?bool
    {
        $setting = $this->connection
            ->table('plugin_setting')
            ->select(['setting_value', 'json_encoded'])
            ->where('plugin_name', $pluginName)
            ->where('user_login', '')
            ->where('setting_name', $settingName)
            ->first();

        return $this->boolean($setting);
    }

    public function siteBoolean(int $idSite, string $pluginName, string $settingName): ?bool
    {
        $setting = $this->connection
            ->table('site_setting')
            ->select(['setting_value', 'json_encoded'])
            ->where('idsite', $idSite)
            ->where('plugin_name', $pluginName)
            ->where('setting_name', $settingName)
            ->first();

        return $this->boolean($setting);
    }

    private function boolean(?object $setting): ?bool
    {
        if ($setting === null) {
            return null;
        }

        $value = $setting->setting_value ?? null;

        if (! is_bool($value) && ! is_int($value) && ! is_string($value)) {
            return null;
        }

        if ((bool) ($setting->json_encoded ?? false)) {
            try {
                $value = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        return is_bool($value) || is_int($value) || is_string($value)
            ? (bool) $value
            : null;
    }
}
