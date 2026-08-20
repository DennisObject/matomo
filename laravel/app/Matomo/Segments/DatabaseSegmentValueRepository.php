<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseSegmentValueRepository implements SegmentValueRepository
{
    private const int MAX_VISITS_TO_SEARCH = 800;

    private const int LOOKBACK_DAYS = 60;

    private const array VISIT_COLUMNS = [
        'userId' => 'user_id',
        'browserCode' => 'config_browser_name',
        'browserVersion' => 'config_browser_version',
        'operatingSystemCode' => 'config_os',
        'operatingSystemVersion' => 'config_os_version',
        'countryCode' => 'location_country',
        'regionCode' => 'location_region',
        'city' => 'location_city',
        'languageCode' => 'location_browser_lang',
        'visitConverted' => 'visit_goal_converted',
    ];

    public function __construct(private ConnectionInterface $connection) {}

    public function supports(string $segmentName): bool
    {
        return isset(self::VISIT_COLUMNS[$segmentName]);
    }

    public function mostFrequent(int $siteId, string $segmentName, int $limit): array
    {
        $column = self::VISIT_COLUMNS[$segmentName] ?? null;
        if ($column === null || $limit < 1) {
            return [];
        }

        $values = $this->connection->table('log_visit')
            ->where('idsite', $siteId)
            ->where(
                'visit_last_action_time',
                '>=',
                CarbonImmutable::now('UTC')->subDays(self::LOOKBACK_DAYS)->startOfDay()->toDateTimeString(),
            )
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->select($column)
            ->orderByDesc('visit_last_action_time')
            ->limit(self::MAX_VISITS_TO_SEARCH)
            ->pluck($column)
            ->filter(static fn (mixed $value): bool => is_float($value) || is_int($value) || is_string($value))
            ->all();

        $counts = [];
        foreach ($values as $value) {
            $value = is_numeric($value) ? (string) round((float) $value, 3) : (string) $value;
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        $values = array_map(static fn (int|string $value): string => (string) $value, array_keys($counts));
        usort($values, static fn (string $left, string $right): int => $counts[$right] <=> $counts[$left] ?: strcmp($left, $right));

        return array_slice($values, 0, $limit);
    }
}
