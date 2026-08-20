<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ActionArchiveMetricsCollecting;
use App\Matomo\Plugins\PluginState;
use Illuminate\Database\Connection;

final readonly class PagePerformanceActionArchiveMetrics
{
    /** @var list<string> */
    private const array DIMENSIONS = [
        'time_network',
        'time_server',
        'time_transfer',
        'time_dom_processing',
        'time_dom_completion',
        'time_on_load',
    ];

    /** @param array<string, int> $caps */
    public function __construct(
        private Connection $connection,
        private PluginState $plugins,
        private array $caps = [],
    ) {}

    public function __invoke(ActionArchiveMetricsCollecting $event): void
    {
        if (! $this->plugins->isActivated('PagePerformance')
            || ! $this->connection->getSchemaBuilder()->hasColumns(
                'log_link_visit_action',
                self::DIMENSIONS,
            )) {
            return;
        }

        $grammar = $this->connection->getQueryGrammar();

        foreach (self::DIMENSIONS as $dimension) {
            $column = $grammar->wrap('log_link_visit_action.'.$dimension);
            $cap = max(0, $this->caps[$dimension] ?? 0);
            $value = $cap === 0
                ? "COALESCE({$column}, 0)"
                : "CASE WHEN COALESCE({$column}, 0) > {$cap} THEN {$cap} ELSE COALESCE({$column}, 0) END";
            $event->metrics[] = new ActionArchiveMetric(
                'sum_'.$dimension,
                new TrustedSegmentSqlExpression("SUM({$value}) / 1000.0"),
                pagesOnly: true,
            );
            $event->metrics[] = new ActionArchiveMetric(
                'nb_hits_with_'.$dimension,
                new TrustedSegmentSqlExpression(
                    "SUM(CASE WHEN {$column} IS NULL THEN 0 ELSE 1 END)",
                ),
                pagesOnly: true,
            );
            $event->metrics[] = new ActionArchiveMetric(
                'min_'.$dimension,
                new TrustedSegmentSqlExpression("MIN({$column}) / 1000.0"),
                aggregation: 'min',
                pagesOnly: true,
            );
            $event->metrics[] = new ActionArchiveMetric(
                'max_'.$dimension,
                new TrustedSegmentSqlExpression("MAX({$column}) / 1000.0"),
                aggregation: 'max',
                pagesOnly: true,
            );
        }
    }
}
