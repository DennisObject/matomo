<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo\Archiving;

use App\Matomo\Archiving\ArchiveGoalMetrics;
use PHPUnit\Framework\TestCase;

class ArchiveGoalMetricsTest extends TestCase
{
    public function test_nests_flat_api_columns_in_the_legacy_archive_shape(): void
    {
        $stored = ArchiveGoalMetrics::nest([
            'label' => 'CH',
            'nb_conversions' => 1,
            'goal_-1_nb_conversions' => 1,
            'goal_-1_items' => 2,
            'goal_0_nb_conversions' => 1,
            'goal_0_revenue' => 125,
            'goal_0_revenue_subtotal' => 100,
            'goal_0_99' => 7,
        ]);

        $this->assertSame([
            'label' => 'CH',
            'nb_conversions' => 1,
            ArchiveGoalMetrics::GOALS_COLUMN => [
                -1 => [1 => 1, 8 => 2],
                0 => [1 => 1, 2 => 125, 4 => 100, 99 => 7],
            ],
        ], $stored);
        $this->assertSame([
            'goal_-1_nb_conversions' => 1,
            'goal_-1_items' => 2,
            'goal_0_nb_conversions' => 1,
            'goal_0_revenue' => 125,
            'goal_0_revenue_subtotal' => 100,
            'goal_0_99' => 7,
        ], ArchiveGoalMetrics::flatten($stored[ArchiveGoalMetrics::GOALS_COLUMN]));
    }

    public function test_rejects_malformed_nested_goal_metrics(): void
    {
        $this->assertNull(ArchiveGoalMetrics::flatten([
            1 => [1 => new \stdClass],
        ]));
    }
}
