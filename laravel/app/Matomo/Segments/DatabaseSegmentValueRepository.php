<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseSegmentValueRepository implements SegmentValueRepository
{
    private const array VISIT_COLUMNS = [
        'userId' => 'user_id',
        'browserCode' => 'config_browser_name',
        'browserVersion' => 'config_browser_version',
        'browserEngine' => 'config_browser_engine',
        'deviceType' => 'config_device_type',
        'deviceBrand' => 'config_device_brand',
        'deviceModel' => 'config_device_model',
        'operatingSystemCode' => 'config_os',
        'operatingSystemVersion' => 'config_os_version',
        'countryCode' => 'location_country',
        'regionCode' => 'location_region',
        'city' => 'location_city',
        'languageCode' => 'location_browser_lang',
        'visitServerHour' => 'visit_server_hour',
        'visitLocalHour' => 'visit_local_hour',
        'visitCount' => 'visitor_count',
        'daysSinceLastVisit' => 'visitor_days_since_last',
        'daysSinceFirstVisit' => 'visitor_days_since_first',
        'visitConverted' => 'visit_goal_converted',
        'visitEcommerceStatus' => 'visit_goal_buyer',
    ];

    public function __construct(private ConnectionInterface $connection) {}

    public function mostFrequent(int $siteId, string $segmentName, int $limit): array
    {
        $column = self::VISIT_COLUMNS[$segmentName] ?? null;
        if ($column === null) {
            return [];
        }

        $values = $this->connection->table('log_visit')
            ->where('idsite', $siteId)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->select($column)
            ->groupBy($column)
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy($column)
            ->limit($limit)
            ->pluck($column)
            ->filter(static fn (mixed $value): bool => is_float($value) || is_int($value) || is_string($value))
            ->values()
            ->all();

        return array_values($values);
    }
}
