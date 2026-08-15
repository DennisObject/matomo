<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ActionArchiveMetricsCollecting;
use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Goals\GoalRepository;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\NumericArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;
use stdClass;

/**
 * @phpstan-type ArchiveValue float|int|string|null
 * @phpstan-type Metrics array<string, ArchiveValue>
 * @phpstan-type ActionReference array{type: int, path: non-empty-list<int|string>, flatLabel: int|string|null, entryVisits: int|float}
 */
final readonly class ActionArchiveCollector
{
    private const string PAGE_URLS = 'Actions_actions_url';

    private const string PAGE_TITLES = 'Actions_actions';

    private const string DOWNLOADS = 'Actions_downloads';

    private const string OUTLINKS = 'Actions_outlink';

    private const string SITE_SEARCH = 'Actions_sitesearch';

    private const string SEARCH_CATEGORIES = 'Actions_SiteSearchCategories';

    private const string PAGE_URLS_FLAT = 'Actions_actions_url_flat';

    private const string PAGE_TITLES_FLAT = 'Actions_actions_flat';

    /** @var array<int, string> */
    private const array RECORDS = [
        ActionArchivePathResolver::PAGE_URL => self::PAGE_URLS,
        ActionArchivePathResolver::OUTLINK => self::OUTLINKS,
        ActionArchivePathResolver::DOWNLOAD => self::DOWNLOADS,
        ActionArchivePathResolver::PAGE_TITLE => self::PAGE_TITLES,
        ActionArchivePathResolver::SITE_SEARCH => self::SITE_SEARCH,
    ];

    /** @var list<string> */
    private const array NUMERIC_RECORDS = [
        'Actions_nb_searches',
        'Actions_nb_keywords',
        'Actions_nb_outlinks',
        'Actions_nb_uniq_outlinks',
        'Actions_nb_pageviews',
        'Actions_nb_uniq_pageviews',
        'Actions_sum_time_generation',
        'Actions_nb_hits_with_time_generation',
        'Actions_nb_downloads',
        'Actions_nb_uniq_downloads',
        'Actions_hits',
    ];

    /** @var list<string> */
    private const array REPORT_METHODS = [
        'Actions.get',
        'Actions.getPageUrls',
        'Actions.getPageUrlsFollowingSiteSearch',
        'Actions.getPageTitlesFollowingSiteSearch',
        'Actions.getEntryPageUrls',
        'Actions.getExitPageUrls',
        'Actions.getPageUrl',
        'Actions.getPageTitles',
        'Actions.getEntryPageTitles',
        'Actions.getExitPageTitles',
        'Actions.getPageTitle',
        'Actions.getDownloads',
        'Actions.getDownload',
        'Actions.getOutlinks',
        'Actions.getOutlink',
        'Actions.getSiteSearchKeywords',
        'Actions.getSiteSearchNoResultKeywords',
        'Actions.getSiteSearchCategories',
    ];

    /** @var list<string> */
    private const array PERFORMANCE_METRICS = [
        'sum_time_generation',
        'nb_hits_with_time_generation',
        'min_time_generation',
        'max_time_generation',
    ];

    /** @var list<string> */
    private const array NON_SUMMABLE_PARENT_COLUMNS = [
        'nb_uniq_visitors',
        'sum_daily_nb_uniq_visitors',
        'entry_nb_uniq_visitors',
        'sum_daily_entry_nb_uniq_visitors',
        'exit_nb_uniq_visitors',
        'sum_daily_exit_nb_uniq_visitors',
    ];

    /** @var array<string, string> */
    private const array PARENT_RENAMES = [
        'nb_uniq_visitors' => 'sum_daily_nb_uniq_visitors',
        'entry_nb_uniq_visitors' => 'sum_daily_entry_nb_uniq_visitors',
        'exit_nb_uniq_visitors' => 'sum_daily_exit_nb_uniq_visitors',
    ];

    private const int CART_GOAL = -1;

    private const int ORDER_GOAL = 0;

    public function __construct(
        private Connection $connection,
        private ArchiveActionQueryFactory $actionQueries,
        private ArchiveConversionQueryFactory $conversionQueries,
        private ArchiveVisitQueryFactory $visitQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private HierarchicalBlobArchiveRepository $blobs,
        private NumericArchiveRepository $numbers,
        private SiteRepository $sites,
        private GoalRepository $goals,
        private PluginState $plugins,
        private ActionArchiveConfiguration $configuration,
        private ActionArchivePathResolver $paths,
        private Dispatcher $events,
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

        $metricEvent = new ActionArchiveMetricsCollecting([], $event->request, $event->period);
        $this->events->dispatch($metricEvent);
        $collectGoals = $this->collectGoals($event->request->siteId);
        $goalIds = $collectGoals ? $this->goalIds($event->request->siteId) : [];
        $operations = $this->aggregationOperations($metricEvent->metrics);
        [$reports, $numeric] = $event->period->label === 'day'
            ? $this->dayRecords(
                $event,
                $timezone,
                $metricEvent->metrics,
                $operations,
                $goalIds,
                $collectGoals,
            )
            : $this->parentRecords($event, $operations);

        foreach ($reports as $recordName => $table) {
            $rootLimit = $recordName === self::SITE_SEARCH
                || $recordName === self::SEARCH_CATEGORIES
                    ? $this->configuration->siteSearchLimit
                    : ($recordName === self::PAGE_URLS_FLAT || $recordName === self::PAGE_TITLES_FLAT
                        ? max(1, $this->configuration->flatLimit)
                        : $this->configuration->rootLimit);
            $serialized = $table->serialized(
                $rootLimit,
                $this->configuration->subtableLimit,
                $recordName === self::SEARCH_CATEGORIES ? 'nb_actions' : 'nb_visits',
            );

            if ($recordName === self::SITE_SEARCH) {
                $root = unserialize($serialized[''], ['allowed_classes' => false]);
                $numeric['Actions_nb_keywords'] = is_array($root) ? count($root) : 0;
            }

            foreach ($serialized as $suffix => $blob) {
                $event->records->addBlob($recordName.$suffix, $blob);
            }
        }

        foreach ($numeric as $recordName => $value) {
            $event->records->addNumeric($recordName, $value);
        }
    }

    /**
     * @param  list<ActionArchiveMetric>  $extensionMetrics
     * @param  array<string, 'max'|'min'>  $operations
     * @param  list<int>  $goalIds
     * @return array{array<string, RecursiveArchiveTable>, array<string, int|float>}
     */
    private function dayRecords(
        ArchiveReportsCollecting $event,
        string $timezone,
        array $extensionMetrics,
        array $operations,
        array $goalIds,
        bool $collectGoals,
    ): array {
        $reports = $this->emptyReports($operations);
        $numeric = array_fill_keys(self::NUMERIC_RECORDS, 0);

        if (! $this->columnsExist()) {
            return [$reports, $numeric];
        }

        /** @var array<int, ActionReference> $actions */
        $actions = [];
        $siteSearchEnabled = (int) ($this->sites->details($event->request->siteId)['sitesearch'] ?? 0) === 1;

        foreach (['idaction_name', 'idaction_url'] as $field) {
            $positions = [];

            foreach ($this->actionRows($event, $timezone, $field, $extensionMetrics) as $source) {
                $type = $source['type'];

                if ($type === ActionArchivePathResolver::SITE_SEARCH && ! $siteSearchEnabled) {
                    continue;
                }

                $metrics = $this->actionMetrics($source['row'], $type, $extensionMetrics);
                $position = $positions[$type] ?? 0;
                $positions[$type] = $position + 1;
                $isPage = in_array($type, [
                    ActionArchivePathResolver::PAGE_URL,
                    ActionArchivePathResolver::PAGE_TITLE,
                ], true);

                if ($this->configuration->rankingLimit > 0
                    && $position >= $this->configuration->rankingLimit) {
                    $path = [-1];
                    $flatLabel = $isPage ? -1 : null;
                } else {
                    $path = $this->paths->path($source['name'], $type, $source['urlPrefix']);
                    $flatLabel = $isPage ? $this->paths->flatLabel($path, $type) : null;
                }

                $metadata = $path === [-1] ? [] : $this->metadata(
                    $source['name'],
                    $type,
                    $source['urlPrefix'],
                );
                $this->mergeAction($reports, $type, $path, $flatLabel, $metrics, $metadata);
                $actions[$source['idAction']] = [
                    'type' => $type,
                    'path' => $path,
                    'flatLabel' => $flatLabel,
                    'entryVisits' => 0,
                ];
                $this->addNumericMetrics($numeric, $type, $metrics);
            }
        }

        $this->mergeEntryMetrics($reports, $actions, $event, $timezone);
        $this->mergeExitMetrics($reports, $actions, $event, $timezone);
        $this->mergeTimeMetrics($reports, $actions, $event, $timezone);

        if ($collectGoals) {
            $this->mergePageGoalMetrics($reports, $actions, $event, $timezone, $goalIds);
            $this->mergeEntryGoalMetrics($reports, $actions, $event, $timezone);
        }

        if ($siteSearchEnabled) {
            $this->mergeSearchCategories($reports[self::SEARCH_CATEGORIES], $event, $timezone);
        }

        $hits = $this->actionQueries->make($event->request, $event->period, $timezone)
            ->distinct()
            ->count('log_link_visit_action.idlink_va');
        $numeric['Actions_hits'] = (int) $hits;

        return [$reports, $numeric];
    }

    /**
     * @param  array<string, 'max'|'min'>  $operations
     * @return array{array<string, RecursiveArchiveTable>, array<string, int|float>}
     */
    private function parentRecords(ArchiveReportsCollecting $event, array $operations): array
    {
        $reports = $this->emptyReports($operations);
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);

        foreach (array_keys($reports) as $recordName) {
            $archives = $this->blobs->records(
                [$event->request->siteId],
                $children,
                $segmentHash,
                $recordName,
                true,
            )[$event->request->siteId] ?? [];

            foreach ($children as $child) {
                $reports[$recordName]->mergeArchiveRecords(
                    $archives[$child->rangeKey()] ?? [],
                    $recordName,
                    self::PARENT_RENAMES,
                );
            }
        }

        $archives = $this->numbers->pluginMetrics(
            [$event->request->siteId],
            $children,
            $segmentHash,
            self::NUMERIC_RECORDS,
            'Actions',
        )[$event->request->siteId] ?? [];
        $numeric = array_fill_keys(self::NUMERIC_RECORDS, 0);

        foreach ($children as $child) {
            foreach ($numeric as $name => $value) {
                $numeric[$name] = $value + ($archives[$child->rangeKey()][$name] ?? 0);
            }
        }

        return [$reports, $numeric];
    }

    /**
     * @param  list<ActionArchiveMetric>  $extensionMetrics
     * @return iterable<array{idAction: int, name: string, type: int, urlPrefix: int|null, row: stdClass}>
     */
    private function actionRows(
        ArchiveReportsCollecting $event,
        string $timezone,
        string $field,
        array $extensionMetrics,
    ): iterable {
        $field = $this->actionField($field);
        $query = $this->actionQueries->make($event->request, $event->period, $timezone)
            ->join('log_action as archived_action', static function (JoinClause $join) use ($field): void {
                $join->on('archived_action.idaction', '=', 'log_link_visit_action.'.$field);
            })
            ->leftJoin('log_action as referrer_name', static function (JoinClause $join): void {
                $join->on(
                    'referrer_name.idaction',
                    '=',
                    'log_link_visit_action.idaction_name_ref',
                );
            })
            ->whereNotNull('log_link_visit_action.'.$field)
            ->whereNull('log_link_visit_action.idaction_event_category')
            ->whereIn('archived_action.type', array_keys(self::RECORDS))
            ->addSelect([
                'archived_action.idaction as archived_idaction',
                'archived_action.name as archived_name',
                'archived_action.type as archived_type',
                'archived_action.url_prefix as archived_url_prefix',
            ])
            ->addSelect($this->baseMetricSelect());

        foreach ($extensionMetrics as $metric) {
            $query->addSelect($this->extensionSelect($metric));
        }

        $query->groupBy([
            'archived_action.idaction',
            'archived_action.name',
            'archived_action.type',
            'archived_action.url_prefix',
        ])->orderBy('archived_action.type')->orderByDesc('nb_hits')->orderBy('archived_action.name');

        foreach ($query->cursor() as $row) {
            $idAction = $this->integer($row->archived_idaction ?? null);
            $name = $row->archived_name ?? null;
            $type = $this->integer($row->archived_type ?? null);
            $prefix = $row->archived_url_prefix ?? null;

            if ($idAction <= 0 || ! is_string($name) || ! isset(self::RECORDS[$type])) {
                continue;
            }

            yield [
                'idAction' => $idAction,
                'name' => $name,
                'type' => $type,
                'urlPrefix' => is_int($prefix) || is_string($prefix) ? (int) $prefix : null,
                'row' => $row,
            ];
        }
    }

    private function baseMetricSelect(): TrustedSegmentSqlExpression
    {
        $grammar = $this->connection->getQueryGrammar();
        $idVisit = $grammar->wrap('log_link_visit_action.idvisit');
        $idVisitor = $grammar->wrap('log_visit.idvisitor');
        $customFloat = $grammar->wrap('log_link_visit_action.custom_float');
        $searchCount = $grammar->wrap('log_link_visit_action.search_count');
        $referrerType = $grammar->wrap('referrer_name.type');

        return new TrustedSegmentSqlExpression(implode(', ', [
            "COUNT(DISTINCT {$idVisit}) AS nb_visits",
            "COUNT(DISTINCT {$idVisitor}) AS nb_uniq_visitors",
            'COUNT(*) AS nb_hits',
            "SUM(CASE WHEN {$customFloat} IS NULL THEN 0 ELSE {$customFloat} END) / 1000 AS sum_time_generation",
            "SUM(CASE WHEN {$customFloat} IS NULL THEN 0 ELSE 1 END) AS nb_hits_with_time_generation",
            "MIN({$customFloat}) / 1000 AS min_time_generation",
            "MAX({$customFloat}) / 1000 AS max_time_generation",
            "MAX(CASE WHEN {$searchCount} = 0 THEN 1 ELSE 0 END) AS site_search_has_no_result",
            "SUM(CASE WHEN {$referrerType} = 8 THEN 1 ELSE 0 END) AS nb_hits_following_search",
        ]));
    }

    private function extensionSelect(ActionArchiveMetric $metric): TrustedSegmentSqlExpression
    {
        $grammar = $this->connection->getQueryGrammar();

        return new TrustedSegmentSqlExpression(
            $metric->expression->getValue($grammar).' AS '.$grammar->wrap($metric->name),
        );
    }

    /**
     * @param  list<ActionArchiveMetric>  $extensionMetrics
     * @return Metrics
     */
    private function actionMetrics(stdClass $row, int $type, array $extensionMetrics): array
    {
        $metrics = [
            'nb_visits' => $this->numeric($row->nb_visits ?? null),
            'nb_uniq_visitors' => $this->numeric($row->nb_uniq_visitors ?? null),
            'nb_hits' => $this->numeric($row->nb_hits ?? null),
            'sum_time_spent' => 0,
            'sum_time_generation' => $this->numeric($row->sum_time_generation ?? null),
            'nb_hits_with_time_generation' => $this->numeric(
                $row->nb_hits_with_time_generation ?? null,
            ),
            'min_time_generation' => $this->nullableNumeric($row->min_time_generation ?? null),
            'max_time_generation' => $this->nullableNumeric($row->max_time_generation ?? null),
            'site_search_has_no_result' => $this->numeric(
                $row->site_search_has_no_result ?? null,
            ),
            'nb_hits_following_search' => $this->numeric(
                $row->nb_hits_following_search ?? null,
            ),
        ];
        $pages = in_array($type, [
            ActionArchivePathResolver::PAGE_URL,
            ActionArchivePathResolver::PAGE_TITLE,
        ], true);

        if (! $pages) {
            foreach (self::PERFORMANCE_METRICS as $name) {
                unset($metrics[$name]);
            }
        }

        if ($type !== ActionArchivePathResolver::SITE_SEARCH) {
            unset($metrics['site_search_has_no_result']);
        } else {
            unset($metrics['nb_uniq_visitors'], $metrics['nb_hits_following_search']);
        }

        foreach ($extensionMetrics as $metric) {
            if (! $metric->pagesOnly || $pages) {
                $metrics[$metric->name] = $this->nullableNumeric($row->{$metric->name} ?? null);
            }
        }

        return $metrics;
    }

    /**
     * @param  array<string, RecursiveArchiveTable>  $reports
     * @param  non-empty-list<string|int>  $path
     * @param  Metrics  $metrics
     * @param  array<string, ArchiveValue>  $metadata
     */
    private function mergeAction(
        array $reports,
        int $type,
        array $path,
        int|string|null $flatLabel,
        array $metrics,
        array $metadata = [],
    ): void {
        $reports[self::RECORDS[$type]]->mergePath($path, $metrics, $metadata);

        if ($flatLabel !== null && $this->configuration->flatLimit > 0) {
            $recordName = $type === ActionArchivePathResolver::PAGE_URL
                ? self::PAGE_URLS_FLAT
                : self::PAGE_TITLES_FLAT;
            $reports[$recordName]->mergePath([$flatLabel], $metrics, $metadata);
        }
    }

    /**
     * @param  array<string, RecursiveArchiveTable>  $reports
     * @param  array<int, ActionReference>  $actions
     */
    private function mergeEntryMetrics(
        array $reports,
        array &$actions,
        ArchiveReportsCollecting $event,
        string $timezone,
    ): void {
        $grammar = $this->connection->getQueryGrammar();
        $visitor = $grammar->wrap('log_visit.idvisitor');
        $actionsCount = $grammar->wrap('log_visit.visit_total_actions');
        $visitTime = $grammar->wrap('log_visit.visit_total_time');
        $select = new TrustedSegmentSqlExpression(implode(', ', [
            "COUNT(DISTINCT {$visitor}) AS entry_nb_uniq_visitors",
            'COUNT(*) AS entry_nb_visits',
            "COALESCE(SUM({$actionsCount}), 0) AS entry_nb_actions",
            "COALESCE(SUM({$visitTime}), 0) AS entry_sum_visit_length",
            "COALESCE(SUM(CASE WHEN {$actionsCount} IN (0, 1) THEN 1 ELSE 0 END), 0) AS entry_bounce_count",
        ]));

        foreach (['visit_entry_idaction_url', 'visit_entry_idaction_name'] as $field) {
            $field = $this->visitActionField($field);
            $query = $this->visitQueries->make($event->request, $event->period, $timezone)
                ->where('log_visit.'.$field, '>', 0)
                ->addSelect('log_visit.'.$field.' as archived_idaction')
                ->addSelect($select)
                ->groupBy('log_visit.'.$field);

            foreach ($query->cursor() as $row) {
                $idAction = $this->integer($row->archived_idaction ?? null);
                $reference = $actions[$idAction] ?? null;

                if ($reference === null
                    || $reference['type'] === ActionArchivePathResolver::SITE_SEARCH) {
                    continue;
                }

                $this->mergeAction(
                    $reports,
                    $reference['type'],
                    $reference['path'],
                    $reference['flatLabel'],
                    [
                        'entry_nb_uniq_visitors' => $this->numeric(
                            $row->entry_nb_uniq_visitors ?? null,
                        ),
                        'entry_nb_visits' => $this->numeric($row->entry_nb_visits ?? null),
                        'entry_nb_actions' => $this->numeric($row->entry_nb_actions ?? null),
                        'entry_sum_visit_length' => $this->numeric(
                            $row->entry_sum_visit_length ?? null,
                        ),
                        'entry_bounce_count' => $this->numeric(
                            $row->entry_bounce_count ?? null,
                        ),
                    ],
                );
                $actions[$idAction]['entryVisits'] = $this->numeric(
                    $row->entry_nb_visits ?? null,
                );
            }
        }
    }

    /**
     * @param  array<string, RecursiveArchiveTable>  $reports
     * @param  array<int, ActionReference>  $actions
     */
    private function mergeExitMetrics(
        array $reports,
        array $actions,
        ArchiveReportsCollecting $event,
        string $timezone,
    ): void {
        $visitor = $this->connection->getQueryGrammar()->wrap('log_visit.idvisitor');
        $select = new TrustedSegmentSqlExpression(implode(', ', [
            "COUNT(DISTINCT {$visitor}) AS exit_nb_uniq_visitors",
            'COUNT(*) AS exit_nb_visits',
        ]));

        foreach (['visit_exit_idaction_url', 'visit_exit_idaction_name'] as $field) {
            $field = $this->visitActionField($field);
            $query = $this->visitQueries->make($event->request, $event->period, $timezone)
                ->where('log_visit.'.$field, '>', 0)
                ->addSelect('log_visit.'.$field.' as archived_idaction')
                ->addSelect($select)
                ->groupBy('log_visit.'.$field);

            foreach ($query->cursor() as $row) {
                $reference = $actions[$this->integer($row->archived_idaction ?? null)] ?? null;

                if ($reference === null) {
                    continue;
                }

                $metrics = ['exit_nb_visits' => $this->numeric($row->exit_nb_visits ?? null)];

                if ($reference['type'] !== ActionArchivePathResolver::SITE_SEARCH) {
                    $metrics['exit_nb_uniq_visitors'] = $this->numeric(
                        $row->exit_nb_uniq_visitors ?? null,
                    );
                }

                $this->mergeAction(
                    $reports,
                    $reference['type'],
                    $reference['path'],
                    $reference['flatLabel'],
                    $metrics,
                );
            }
        }
    }

    /**
     * @param  array<string, RecursiveArchiveTable>  $reports
     * @param  array<int, ActionReference>  $actions
     */
    private function mergeTimeMetrics(
        array $reports,
        array $actions,
        ArchiveReportsCollecting $event,
        string $timezone,
    ): void {
        $time = $this->connection->getQueryGrammar()->wrap(
            'log_link_visit_action.time_spent_ref_action',
        );

        foreach (['idaction_url_ref', 'idaction_name_ref'] as $field) {
            $field = $this->actionField($field);
            $query = $this->actionQueries->make($event->request, $event->period, $timezone)
                ->where('log_link_visit_action.'.$field, '>', 0)
                ->where('log_link_visit_action.time_spent_ref_action', '>', 0)
                ->whereNull('log_link_visit_action.idaction_event_category')
                ->addSelect('log_link_visit_action.'.$field.' as archived_idaction')
                ->addSelect(new TrustedSegmentSqlExpression(
                    "COALESCE(SUM({$time}), 0) AS sum_time_spent",
                ))
                ->groupBy('log_link_visit_action.'.$field);

            foreach ($query->cursor() as $row) {
                $reference = $actions[$this->integer($row->archived_idaction ?? null)] ?? null;

                if ($reference === null) {
                    continue;
                }

                $this->mergeAction(
                    $reports,
                    $reference['type'],
                    $reference['path'],
                    $reference['flatLabel'],
                    ['sum_time_spent' => $this->numeric($row->sum_time_spent ?? null)],
                );
            }
        }
    }

    /**
     * @param  array<string, RecursiveArchiveTable>  $reports
     * @param  array<int, ActionReference>  $actions
     * @param  list<int>  $goalIds
     */
    private function mergePageGoalMetrics(
        array $reports,
        array $actions,
        ArchiveReportsCollecting $event,
        string $timezone,
        array $goalIds,
    ): void {
        if (! $this->pageGoalColumnsExist()) {
            return;
        }

        foreach ($goalIds as $goalId) {
            foreach ([
                'idaction_url' => ActionArchivePathResolver::PAGE_URL,
                'idaction_name' => ActionArchivePathResolver::PAGE_TITLE,
            ] as $field => $type) {
                $field = $this->actionField($field);
                $query = $this->conversionQueries
                    ->make($event->request, $event->period, $timezone)
                    ->join(
                        'log_link_visit_action as conversion_action',
                        static function (JoinClause $join): void {
                            $join->on(
                                'conversion_action.idvisit',
                                '=',
                                'log_conversion.idvisit',
                            )->on(
                                'conversion_action.idsite',
                                '=',
                                'log_conversion.idsite',
                            );
                        },
                    )
                    ->join(
                        'log_action as conversion_action_definition',
                        static function (JoinClause $join) use ($field): void {
                            $join->on(
                                'conversion_action_definition.idaction',
                                '=',
                                'conversion_action.'.$field,
                            );
                        },
                    )
                    ->where('log_conversion.idgoal', $goalId)
                    ->where('conversion_action_definition.type', $type)
                    ->whereColumn(
                        'conversion_action.server_time',
                        '<=',
                        'log_conversion.server_time',
                    )
                    ->addSelect([
                        'log_conversion.idvisit as conversion_visit',
                        'conversion_action_definition.idaction as archived_idaction',
                    ])
                    ->addSelect($this->pageGoalMetricSelect())
                    ->groupBy([
                        'log_conversion.idvisit',
                        'conversion_action_definition.idaction',
                    ]);

                foreach ($query->cursor() as $row) {
                    $reference = $actions[$this->integer(
                        $row->archived_idaction ?? null,
                    )] ?? null;

                    if ($reference === null || $reference['type'] !== $type) {
                        continue;
                    }

                    $prefix = 'goal_'.$goalId.'_';
                    $this->mergeAction(
                        $reports,
                        $type,
                        $reference['path'],
                        $reference['flatLabel'],
                        [
                            $prefix.'nb_conversions' => $this->numeric(
                                $row->goal_nb_conversions ?? null,
                            ),
                            $prefix.'revenue' => $this->numeric(
                                $row->goal_revenue ?? null,
                            ),
                            $prefix.'nb_conv_pages_before' => $this->numeric(
                                $row->goal_nb_conv_pages_before ?? null,
                            ),
                            $prefix.'nb_conversions_attrib' => $this->numericWithPrecision(
                                $row->goal_nb_conversions_attrib ?? null,
                                4,
                            ),
                            $prefix.'nb_conversions_page_rate' => 0,
                            $prefix.'nb_conversions_page_uniq' => $this->numeric(
                                $row->goal_nb_conversions_page_uniq ?? null,
                            ),
                            $prefix.'revenue_attrib' => $this->numeric(
                                $row->goal_revenue_attrib ?? null,
                            ),
                        ],
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, RecursiveArchiveTable>  $reports
     * @param  array<int, ActionReference>  $actions
     */
    private function mergeEntryGoalMetrics(
        array $reports,
        array $actions,
        ArchiveReportsCollecting $event,
        string $timezone,
    ): void {
        if (! $this->entryGoalColumnsExist()) {
            return;
        }

        /** @var array<string, int|float> $entrances */
        $entrances = [];

        foreach ($actions as $reference) {
            if (in_array($reference['type'], [
                ActionArchivePathResolver::PAGE_URL,
                ActionArchivePathResolver::PAGE_TITLE,
            ], true)) {
                $key = $this->actionReferenceKey($reference);
                $entrances[$key] = ($entrances[$key] ?? 0) + $reference['entryVisits'];
            }
        }

        /**
         * @var array<string, array{
         *     reference: ActionReference,
         *     goalId: int,
         *     conversions: int|float,
         *     revenue: int|float
         * }> $goalMetrics
         */
        $goalMetrics = [];

        foreach ([
            'visit_entry_idaction_url' => ActionArchivePathResolver::PAGE_URL,
            'visit_entry_idaction_name' => ActionArchivePathResolver::PAGE_TITLE,
        ] as $field => $type) {
            $field = $this->visitActionField($field);
            $query = $this->conversionQueries
                ->make($event->request, $event->period, $timezone)
                ->join(
                    'log_action as conversion_entry_action',
                    static function (JoinClause $join) use ($field): void {
                        $join->on(
                            'conversion_entry_action.idaction',
                            '=',
                            'log_visit.'.$field,
                        );
                    },
                )
                ->whereNotNull('log_visit.'.$field)
                ->where('log_conversion.idgoal', '>=', self::ORDER_GOAL)
                ->where('conversion_entry_action.type', $type)
                ->addSelect([
                    'log_visit.'.$field.' as archived_idaction',
                    'log_conversion.idgoal as archived_idgoal',
                ])
                ->addSelect($this->entryGoalMetricSelect())
                ->groupBy([
                    'log_visit.'.$field,
                    'log_conversion.idgoal',
                    'conversion_entry_action.type',
                ]);

            foreach ($query->cursor() as $row) {
                $reference = $actions[$this->integer($row->archived_idaction ?? null)] ?? null;
                $goalId = $this->integer($row->archived_idgoal ?? null);

                if ($reference === null || $reference['type'] !== $type || $goalId < 0) {
                    continue;
                }

                $referenceKey = $this->actionReferenceKey($reference);
                $key = $referenceKey."\0".$goalId;
                $goalMetrics[$key] ??= [
                    'reference' => $reference,
                    'goalId' => $goalId,
                    'conversions' => 0,
                    'revenue' => 0,
                ];
                $goalMetrics[$key]['conversions'] += $this->numeric(
                    $row->goal_nb_conversions_entry ?? null,
                );
                $goalMetrics[$key]['revenue'] += $this->numeric(
                    $row->goal_revenue_entry ?? null,
                );
            }
        }

        foreach ($goalMetrics as $row) {
            $reference = $row['reference'];
            $prefix = 'goal_'.$row['goalId'].'_';
            $metrics = [
                $prefix.'revenue_entry' => $row['revenue'],
                $prefix.'nb_conversions_entry' => $row['conversions'],
            ];
            $entryVisits = $entrances[$this->actionReferenceKey($reference)] ?? 0;

            if ($entryVisits > 0) {
                $metrics[$prefix.'nb_conversions_entry_rate'] = $this->numericWithPrecision(
                    $row['conversions'] / $entryVisits,
                    3,
                );
                $metrics[$prefix.'revenue_per_entry'] = $this->numericWithPrecision(
                    $row['revenue'] / $entryVisits,
                    3,
                );
            }

            $this->mergeAction(
                $reports,
                $reference['type'],
                $reference['path'],
                $reference['flatLabel'],
                $metrics,
            );
        }
    }

    /** @param ActionReference $reference */
    private function actionReferenceKey(array $reference): string
    {
        return $reference['type']."\0".serialize($reference['path']);
    }

    private function pageGoalMetricSelect(): TrustedSegmentSqlExpression
    {
        $grammar = $this->connection->getQueryGrammar();
        $revenue = $grammar->wrap('log_conversion.revenue');
        $pageviews = $grammar->wrap('log_conversion.pageviews_before');
        $boundedRevenue = "CASE WHEN ABS({$revenue}) > 1000000000000 THEN 0 ELSE COALESCE({$revenue}, 0) END";

        return new TrustedSegmentSqlExpression(implode(', ', [
            'COUNT(*) AS goal_nb_conversions',
            "ROUND(SUM({$boundedRevenue}), 2) AS goal_revenue",
            "MAX(COALESCE({$pageviews}, 0)) AS goal_nb_conv_pages_before",
            "ROUND(SUM(CASE WHEN {$pageviews} > 0 THEN 1.0 / {$pageviews} ELSE 0 END), 4) AS goal_nb_conversions_attrib",
            '0 AS goal_nb_conversions_page_rate',
            'COUNT(*) AS goal_nb_conversions_page_uniq',
            "ROUND(SUM(CASE WHEN {$pageviews} > 0 THEN {$boundedRevenue} / {$pageviews} ELSE 0 END), 2) AS goal_revenue_attrib",
        ]));
    }

    private function entryGoalMetricSelect(): TrustedSegmentSqlExpression
    {
        $revenue = $this->connection->getQueryGrammar()->wrap('log_conversion.revenue');

        return new TrustedSegmentSqlExpression(implode(', ', [
            'COUNT(*) AS goal_nb_conversions_entry',
            "ROUND(SUM(CASE WHEN ABS({$revenue}) > 1000000000000 THEN 0 ELSE COALESCE({$revenue}, 0) END), 2) AS goal_revenue_entry",
        ]));
    }

    private function mergeSearchCategories(
        RecursiveArchiveTable $table,
        ArchiveReportsCollecting $event,
        string $timezone,
    ): void {
        $grammar = $this->connection->getQueryGrammar();
        $visitor = $grammar->wrap('log_visit.idvisitor');
        $visit = $grammar->wrap('log_link_visit_action.idvisit');
        $query = $this->actionQueries->make($event->request, $event->period, $timezone)
            ->whereNotNull('log_link_visit_action.search_cat')
            ->where('log_link_visit_action.search_cat', '!=', '')
            ->addSelect('log_link_visit_action.search_cat as category')
            ->addSelect(new TrustedSegmentSqlExpression(implode(', ', [
                "COUNT(DISTINCT {$visitor}) AS nb_uniq_visitors",
                "COUNT(DISTINCT {$visit}) AS nb_visits",
                'COUNT(*) AS nb_actions',
            ])))
            ->groupBy('log_link_visit_action.search_cat')
            ->orderByDesc('nb_visits')
            ->orderBy('log_link_visit_action.search_cat');

        foreach ($query->cursor() as $row) {
            $category = $row->category ?? null;

            if (! is_string($category) || $category === '') {
                continue;
            }

            $table->mergePath([$category], [
                'nb_uniq_visitors' => $this->numeric($row->nb_uniq_visitors ?? null),
                'nb_visits' => $this->numeric($row->nb_visits ?? null),
                'nb_actions' => $this->numeric($row->nb_actions ?? null),
            ]);
        }
    }

    /**
     * @param  array<string, int|float>  $numeric
     * @param  Metrics  $metrics
     */
    private function addNumericMetrics(array &$numeric, int $type, array $metrics): void
    {
        $hits = $this->numeric($metrics['nb_hits'] ?? null);
        $visits = $this->numeric($metrics['nb_visits'] ?? null);

        if ($type === ActionArchivePathResolver::PAGE_URL) {
            $numeric['Actions_nb_pageviews'] += $hits;
            $numeric['Actions_nb_uniq_pageviews'] += $visits;
            $numeric['Actions_sum_time_generation'] += $this->numeric(
                $metrics['sum_time_generation'] ?? null,
            );
            $numeric['Actions_nb_hits_with_time_generation'] += $this->numeric(
                $metrics['nb_hits_with_time_generation'] ?? null,
            );
        } elseif ($type === ActionArchivePathResolver::OUTLINK) {
            $numeric['Actions_nb_outlinks'] += $hits;
            $numeric['Actions_nb_uniq_outlinks'] += $visits;
        } elseif ($type === ActionArchivePathResolver::DOWNLOAD) {
            $numeric['Actions_nb_downloads'] += $hits;
            $numeric['Actions_nb_uniq_downloads'] += $visits;
        } elseif ($type === ActionArchivePathResolver::SITE_SEARCH) {
            $numeric['Actions_nb_searches'] += $hits;
        }
    }

    /**
     * @param  array<string, 'max'|'min'>  $operations
     * @return array<string, RecursiveArchiveTable>
     */
    private function emptyReports(array $operations): array
    {
        $reports = [];

        foreach (array_unique(self::RECORDS) as $recordName) {
            $reports[$recordName] = new RecursiveArchiveTable(
                $operations,
                self::NON_SUMMABLE_PARENT_COLUMNS,
            );
        }

        $reports[self::SEARCH_CATEGORIES] = new RecursiveArchiveTable;

        if ($this->configuration->flatLimit > 0) {
            $reports[self::PAGE_URLS_FLAT] = new RecursiveArchiveTable($operations);
            $reports[self::PAGE_TITLES_FLAT] = new RecursiveArchiveTable($operations);
        }

        return $reports;
    }

    /**
     * @param  list<ActionArchiveMetric>  $metrics
     * @return array<string, 'max'|'min'>
     */
    private function aggregationOperations(array $metrics): array
    {
        $operations = [
            'min_time_generation' => 'min',
            'max_time_generation' => 'max',
        ];

        foreach ($metrics as $metric) {
            if ($metric->aggregation !== 'sum') {
                $operations[$metric->name] = $metric->aggregation;
            }
        }

        return $operations;
    }

    private function collectGoals(int $siteId): bool
    {
        return $this->plugins->isActivated('Goals')
            && ! $this->configuration->goalsDisabled($siteId);
    }

    /** @return list<int> */
    private function goalIds(int $siteId): array
    {
        $goalIds = [];

        foreach ($this->goals->activeForSites([$siteId]) as $goal) {
            if (is_numeric($goal['idgoal'] ?? null)) {
                $goalIds[] = (int) $goal['idgoal'];
            }
        }

        if ((int) ($this->sites->details($siteId)['ecommerce'] ?? 0) === 1) {
            $goalIds[] = self::CART_GOAL;
            $goalIds[] = self::ORDER_GOAL;
        }

        $goalIds = array_values(array_unique($goalIds));
        sort($goalIds);

        return $goalIds;
    }

    /** @return array<string, ArchiveValue> */
    private function metadata(string $name, int $type, ?int $prefix): array
    {
        if (in_array($type, [
            ActionArchivePathResolver::PAGE_URL,
            ActionArchivePathResolver::OUTLINK,
            ActionArchivePathResolver::DOWNLOAD,
        ], true)) {
            return ['url' => $this->paths->reconstructedUrl($name, $prefix)];
        }

        return $type === ActionArchivePathResolver::PAGE_TITLE
            ? ['page_title_path' => $name]
            : [];
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'Actions';
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, self::REPORT_METHODS) !== [];
    }

    private function columnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_link_visit_action', [
            'idlink_va',
            'idsite',
            'idvisit',
            'idaction_url',
            'idaction_name',
            'idaction_url_ref',
            'idaction_name_ref',
            'idaction_event_category',
            'custom_float',
            'search_count',
            'search_cat',
            'time_spent_ref_action',
            'server_time',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_action', [
            'idaction',
            'name',
            'type',
            'url_prefix',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_visit', [
            'idsite',
            'idvisit',
            'idvisitor',
            'visit_entry_idaction_url',
            'visit_entry_idaction_name',
            'visit_exit_idaction_url',
            'visit_exit_idaction_name',
            'visit_total_actions',
            'visit_total_time',
            'visit_last_action_time',
        ]);
    }

    private function pageGoalColumnsExist(): bool
    {
        return $this->entryGoalColumnsExist()
            && $this->connection->getSchemaBuilder()->hasColumn(
                'log_conversion',
                'pageviews_before',
            );
    }

    private function entryGoalColumnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_conversion', [
            'idsite',
            'idvisit',
            'idgoal',
            'revenue',
            'server_time',
        ]);
    }

    private function actionField(string $field): string
    {
        return match ($field) {
            'idaction_name' => 'idaction_name',
            'idaction_url' => 'idaction_url',
            'idaction_name_ref' => 'idaction_name_ref',
            'idaction_url_ref' => 'idaction_url_ref',
            default => throw new InvalidArgumentException('The action field is invalid.'),
        };
    }

    private function visitActionField(string $field): string
    {
        return match ($field) {
            'visit_entry_idaction_url' => 'visit_entry_idaction_url',
            'visit_entry_idaction_name' => 'visit_entry_idaction_name',
            'visit_exit_idaction_url' => 'visit_exit_idaction_url',
            'visit_exit_idaction_name' => 'visit_exit_idaction_name',
            default => throw new InvalidArgumentException('The visit action field is invalid.'),
        };
    }

    private function integer(mixed $value): int
    {
        return is_int($value) || is_string($value) ? (int) $value : 0;
    }

    private function numeric(mixed $value): int|float
    {
        return $this->nullableNumeric($value) ?? 0;
    }

    private function numericWithPrecision(mixed $value, int $precision): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = round((float) $value, $precision);

        return floor($number) === $number ? (int) $number : $number;
    }

    private function nullableNumeric(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = round((float) $value, 2);

        return floor($number) === $number ? (int) $number : $number;
    }
}
