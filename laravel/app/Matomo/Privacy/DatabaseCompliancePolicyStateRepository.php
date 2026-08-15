<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseCompliancePolicyStateRepository implements CompliancePolicyStateRepository
{
    private const string PLUGIN = 'CnilPolicy';

    private const string SETTING = 'cnil_v1_policy_enabled';

    public function __construct(private ConnectionInterface $connection) {}

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
        $value = $this->connection->table('plugin_setting')
            ->where('plugin_name', self::PLUGIN)
            ->where('user_login', '')
            ->where('setting_name', self::SETTING)
            ->value('setting_value');

        return in_array($value, [true, 1, '1'], true);
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
