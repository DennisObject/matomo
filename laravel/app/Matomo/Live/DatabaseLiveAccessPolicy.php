<?php

declare(strict_types=1);

namespace App\Matomo\Live;

use Illuminate\Database\Connection;

final readonly class DatabaseLiveAccessPolicy implements LiveAccessPolicy
{
    public function __construct(private Connection $connection) {}

    public function visitorLogEnabled(int $siteId): bool
    {
        return ! $this->disabled($siteId, 'disable_visitor_log');
    }

    public function visitorProfileEnabled(int $siteId): bool
    {
        return $this->visitorLogEnabled($siteId)
            && ! $this->disabled($siteId, 'disable_visitor_profile');
    }

    private function disabled(int $siteId, string $setting): bool
    {
        if (! $this->connection->getSchemaBuilder()->hasTable('site_setting')) {
            return false;
        }

        $value = $this->connection->table('site_setting')
            ->where('idsite', $siteId)
            ->where('plugin_name', 'Live')
            ->where('setting_name', $setting)
            ->value('setting_value');

        return in_array($value, [true, 1, '1'], true);
    }
}
