<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseCompliancePolicyStateRepository implements CompliancePolicyStateRepository
{
    private const string PLUGIN = 'CnilPolicy';

    private const string SETTING = 'cnil_v1_policy_enabled';

    public function __construct(
        private ConnectionInterface $connection,
        private ?bool $configuredPolicy,
    ) {}

    public function active(?int $idSite): bool
    {
        if ($this->configuredPolicy !== null) {
            return $this->configuredPolicy;
        }

        if ($this->storedBoolean('plugin_setting', null, self::PLUGIN, self::SETTING) === true) {
            return true;
        }

        return $idSite !== null
            && $this->storedBoolean('site_setting', $idSite, self::PLUGIN, self::SETTING) === true;
    }

    public function configControlled(): bool
    {
        return $this->configuredPolicy !== null;
    }

    public function settingEnforced(string $plugin, string $setting, ?int $idSite): bool
    {
        if ($this->configControlled()) {
            return $this->active($idSite);
        }

        $system = $this->storedBoolean('plugin_setting', null, $plugin, $setting.'_policy_enforced');
        $site = $idSite === null
            ? null
            : $this->storedBoolean('site_setting', $idSite, $plugin, $setting.'_policy_enforced');

        if ($system === true || $site === true) {
            return true;
        }

        if ($system === false || $site === false) {
            return false;
        }

        return $this->active($idSite);
    }

    public function setActive(?int $idSite, bool $active): void
    {
        $this->connection->transaction(function () use ($idSite, $active): void {
            if ($idSite === null) {
                $this->setSystem($active);

                return;
            }

            $this->connection->table('site_setting')->updateOrInsert(
                [
                    'idsite' => $idSite,
                    'plugin_name' => self::PLUGIN,
                    'setting_name' => self::SETTING,
                ],
                [
                    'setting_value' => $active ? '1' : '0',
                    'json_encoded' => 0,
                ],
            );

            if (! $active && $this->systemIsActive()) {
                $this->setSystem(false);
            }
        });
    }

    private function systemIsActive(): bool
    {
        return $this->storedBoolean('plugin_setting', null, self::PLUGIN, self::SETTING) === true;
    }

    private function storedBoolean(
        string $table,
        ?int $idSite,
        string $plugin,
        string $setting,
    ): ?bool {
        $query = $this->connection->table($table)
            ->where('plugin_name', $plugin)
            ->where('setting_name', $setting);
        if ($idSite === null) {
            $query->where('user_login', '');
        } else {
            $query->where('idsite', $idSite);
        }

        $row = $query->select(['setting_value', 'json_encoded'])->first();
        if ($row === null || ! is_scalar($row->setting_value ?? null)) {
            return null;
        }

        $value = (string) $row->setting_value;
        if ((bool) ($row->json_encoded ?? false)) {
            $decoded = json_decode($value, true);

            return is_bool($decoded) ? $decoded : null;
        }

        return match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false', '' => false,
            default => null,
        };
    }

    private function setSystem(bool $active): void
    {
        $this->connection->table('plugin_setting')->updateOrInsert(
            [
                'plugin_name' => self::PLUGIN,
                'user_login' => '',
                'setting_name' => self::SETTING,
            ],
            [
                'setting_value' => $active ? '1' : '0',
                'json_encoded' => 0,
            ],
        );
    }
}
