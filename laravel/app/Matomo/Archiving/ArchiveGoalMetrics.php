<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

final class ArchiveGoalMetrics
{
    public const int GOALS_COLUMN = 10;

    /** @var array<int, string> */
    private const array NAMES = [
        1 => 'nb_conversions',
        2 => 'revenue',
        3 => 'nb_visits_converted',
        4 => 'revenue_subtotal',
        5 => 'revenue_tax',
        6 => 'revenue_shipping',
        7 => 'revenue_discount',
        8 => 'items',
        9 => 'nb_conv_pages_before',
        10 => 'nb_conversions_attrib',
        11 => 'nb_conversions_page_rate',
        12 => 'nb_conversions_page_uniq',
        13 => 'nb_conversions_entry_rate',
        14 => 'revenue_per_entry',
        15 => 'revenue_attrib',
        16 => 'nb_conversions_entry',
        17 => 'revenue_entry',
    ];

    /**
     * @param  array<int|string, mixed>  $goals
     * @return array<string, float|int|string|null>|null
     */
    public static function flatten(array $goals): ?array
    {
        $columns = [];

        foreach ($goals as $goalId => $metrics) {
            if (preg_match('/^-?[0-9]+$/D', (string) $goalId) !== 1
                || ! is_array($metrics)) {
                return null;
            }

            foreach ($metrics as $metric => $value) {
                if (preg_match('/^[0-9]+$/D', (string) $metric) !== 1
                    || ! is_float($value) && ! is_int($value) && ! is_string($value) && $value !== null) {
                    return null;
                }

                $name = self::NAMES[(int) $metric] ?? (string) $metric;
                $columns['goal_'.(int) $goalId.'_'.$name] = $value;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, float|int|string|null>  $columns
     * @return array<int|string, float|int|string|null|array<int, array<int, float|int|string|null>>>
     */
    public static function nest(array $columns): array
    {
        $stored = [];
        $metricIds = array_flip(self::NAMES);
        $goals = [];

        foreach ($columns as $name => $value) {
            if (preg_match('/^goal_(-?[0-9]+)_(.+)$/D', $name, $matches) !== 1) {
                $stored[$name] = $value;

                continue;
            }

            $metricId = $metricIds[$matches[2]] ?? null;

            if ($metricId === null && preg_match('/^[0-9]+$/D', $matches[2]) === 1) {
                $metricId = (int) $matches[2];
            }

            if ($metricId === null) {
                $stored[$name] = $value;

                continue;
            }

            $goals[(int) $matches[1]][$metricId] = $value;
        }

        if ($goals !== []) {
            ksort($goals);

            foreach ($goals as &$metrics) {
                ksort($metrics);
            }

            unset($metrics);
            $stored[self::GOALS_COLUMN] = $goals;
        }

        return $stored;
    }
}
