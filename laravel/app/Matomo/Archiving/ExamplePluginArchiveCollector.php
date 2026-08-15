<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Reporting\BlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use stdClass;

final readonly class ExamplePluginArchiveCollector
{
    private const string METRIC = 'ExamplePlugin_example_metric';

    private const string STATEFUL_METRIC = 'ExamplePlugin_example_metric2';

    private const string BLOB = 'ExamplePlugin_exampleBlob';

    private const string DAYS_FROM = '2016-07-08';

    private const int ROW_LIMIT = 500;

    /** @var list<string> */
    private const array REPORTS = ['ExamplePlugin.getExampleReport'];

    public function __construct(
        private Connection $connection,
        private ArchiveVisitQueryFactory $visitQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private BlobArchiveRepository $blobs,
        private NumericArchiveRepository $numbers,
        private MutableOptionRepository $options,
        private SiteRepository $sites,
    ) {}

    public function __invoke(ArchiveReportsCollecting $event): void
    {
        if (! $this->requested($event)) {
            return;
        }

        $timezone = $this->sites->timezone($event->request->siteId);

        if ($timezone === null) {
            return;
        }

        if ($event->period->label === 'day') {
            [$metrics, $table] = $this->dayRecords($event, $timezone);
        } else {
            [$metrics, $table] = $this->parentRecords($event);
        }

        foreach ($metrics as $name => $value) {
            $event->records->addNumeric($name, $value);
        }

        $event->records->addBlob(
            self::BLOB,
            $table->serialized(self::ROW_LIMIT, self::ROW_LIMIT, 'nb_visits')[''],
        );
    }

    /** @return array{array<string, int|float>, HierarchicalArchiveTable} */
    private function dayRecords(
        ArchiveReportsCollecting $event,
        string $timezone,
    ): array {
        $table = new HierarchicalArchiveTable;

        if ($this->columnsExist()) {
            $query = $this->visitQueries->make($event->request, $event->period, $timezone)
                ->addSelect('log_visit.idvisitor')
                ->selectRaw($this->metricSelect())
                ->groupBy('log_visit.idvisitor')
                ->orderByDesc('nb_visits');

            foreach ($query->cursor() as $result) {
                $label = $result->idvisitor ?? null;

                if (! is_float($label) && ! is_int($label) && ! is_string($label)) {
                    continue;
                }

                $table->mergeRoot((string) $label, $this->metrics($result));
            }
        }

        $start = CarbonImmutable::parse($event->period->startDate, 'UTC')->startOfDay();
        $daysFrom = CarbonImmutable::parse(self::DAYS_FROM, 'UTC')->startOfDay();
        $difference = (int) round(($daysFrom->getTimestamp() - $start->getTimestamp()) / 86_400);
        $optionName = 'ExamplePlugin.metricValue.'.md5(implode('.', [
            $event->request->siteId,
            $event->period->rangeKey(),
            $event->period->label,
            $this->segments->resolve($event->request->segment),
        ]));
        $callCount = (int) $this->options->value($optionName);
        $this->options->set($optionName, (string) ($callCount + 1));

        return [[
            self::METRIC => $difference,
            self::STATEFUL_METRIC => $callCount > 0 ? 1 : 0,
        ], $table];
    }

    /** @return array{array<string, int|float>, HierarchicalArchiveTable} */
    private function parentRecords(ArchiveReportsCollecting $event): array
    {
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);
        $archives = $this->numbers->pluginMetrics(
            [$event->request->siteId],
            $children,
            $segmentHash,
            [self::METRIC, self::STATEFUL_METRIC],
            'ExamplePlugin',
        )[$event->request->siteId] ?? [];
        $blobRows = $this->blobs->rows(
            [$event->request->siteId],
            $children,
            $segmentHash,
            self::BLOB,
        )[$event->request->siteId] ?? [];
        $metrics = [self::METRIC => 0, self::STATEFUL_METRIC => 0];
        $table = new HierarchicalArchiveTable;

        foreach ($children as $child) {
            foreach ($metrics as $name => $value) {
                $metrics[$name] = $value + ($archives[$child->rangeKey()][$name] ?? 0);
            }

            foreach ($blobRows[$child->rangeKey()] ?? [] as $row) {
                $label = $row['columns']['label'] ?? null;

                if (is_float($label) || is_int($label) || is_string($label)) {
                    $table->mergeRoot($label, $row['columns']);
                }
            }
        }

        return [$metrics, $table];
    }

    /** @return literal-string */
    private function metricSelect(): string
    {
        return implode(', ', [
            'COUNT(DISTINCT idvisitor) AS nb_uniq_visitors',
            'COUNT(*) AS nb_visits',
            'COALESCE(SUM(visit_total_actions), 0) AS nb_actions',
            'COALESCE(MAX(visit_total_actions), 0) AS max_actions',
            'COALESCE(SUM(visit_total_time), 0) AS sum_visit_length',
            'COALESCE(SUM(CASE WHEN visit_total_actions IN (0, 1) THEN 1 ELSE 0 END), 0) AS bounce_count',
            'COALESCE(SUM(CASE WHEN visit_goal_converted = 1 THEN 1 ELSE 0 END), 0) AS nb_visits_converted',
        ]);
    }

    /** @return array<string, int|float> */
    private function metrics(stdClass $result): array
    {
        return [
            'nb_uniq_visitors' => $this->numeric($result->nb_uniq_visitors ?? null),
            'nb_visits' => $this->numeric($result->nb_visits ?? null),
            'nb_actions' => $this->numeric($result->nb_actions ?? null),
            'max_actions' => $this->numeric($result->max_actions ?? null),
            'sum_visit_length' => $this->numeric($result->sum_visit_length ?? null),
            'bounce_count' => $this->numeric($result->bounce_count ?? null),
            'nb_visits_converted' => $this->numeric($result->nb_visits_converted ?? null),
        ];
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'ExamplePlugin';
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, self::REPORTS) !== [];
    }

    private function columnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_visit', [
            'idsite',
            'idvisitor',
            'visit_last_action_time',
            'visit_total_actions',
            'visit_total_time',
            'visit_goal_converted',
        ]);
    }

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
