<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

use App\Matomo\Archiving\Events\ArchiveReportsCollecting;
use App\Matomo\Reporting\HierarchicalBlobArchiveRepository;
use App\Matomo\Reporting\SegmentHashResolver;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use stdClass;

/**
 * @phpstan-type ImpressionRow array{
 *     contentPiece: string,
 *     contentName: string,
 *     nb_uniq_visitors: int|float,
 *     nb_visits: int|float,
 *     nb_impressions: int|float
 * }
 * @phpstan-type InteractionRow array{
 *     contentPiece: string,
 *     contentInteraction: string,
 *     contentName: string,
 *     nb_interactions: int|float
 * }
 */
final readonly class ContentArchiveCollector
{
    private const int ROOT_LIMIT = 500;

    private const int SUBTABLE_LIMIT = 100;

    private const int RANKING_QUERY_LIMIT = 50_000;

    private const string CONTENT_PIECE_NOT_SET = 'Piwik_ContentPieceNotSet';

    private const string RANKING_SUMMARY = '__mtm_ranking_query_others__';

    /** @var array<string, array{string, string}> */
    private const array RECORDS = [
        'Contents_piece_name' => ['contentPiece', 'contentName'],
        'Contents_name_piece' => ['contentName', 'contentPiece'],
    ];

    /** @var list<string> */
    private const array REPORTS = [
        'Contents.getContentNames',
        'Contents.getContentPieces',
    ];

    public function __construct(
        private Connection $connection,
        private ArchiveActionQueryFactory $actionQueries,
        private ReportingSubperiodFactory $subperiods,
        private SegmentHashResolver $segments,
        private HierarchicalBlobArchiveRepository $blobs,
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

        $reports = $event->period->label === 'day'
            ? $this->dayReports($event, $timezone)
            : $this->parentReports($event);

        foreach (array_keys(self::RECORDS) as $recordName) {
            foreach ($reports[$recordName]->serialized(
                self::ROOT_LIMIT,
                self::SUBTABLE_LIMIT,
                'nb_visits',
            ) as $suffix => $blob) {
                $event->records->addBlob($recordName.$suffix, $blob);
            }
        }
    }

    /** @return array<string, HierarchicalArchiveTable> */
    private function dayReports(ArchiveReportsCollecting $event, string $timezone): array
    {
        $reports = $this->emptyReports();

        if (! $this->columnsExist()) {
            return $reports;
        }

        foreach ($this->impressionRows($event, $timezone) as $source) {
            $metrics = [
                'nb_uniq_visitors' => $source['nb_uniq_visitors'],
                'nb_visits' => $source['nb_visits'],
                'nb_impressions' => $source['nb_impressions'],
                'nb_interactions' => 0,
            ];

            foreach (self::RECORDS as $recordName => [$mainDimension, $subDimension]) {
                $mainLabel = $this->impressionMainLabel(
                    $source[$mainDimension],
                    $mainDimension,
                );
                $reports[$recordName]->mergeRoot($mainLabel, $metrics);
                $subLabel = $source[$subDimension];

                if ($this->emptyLabel($subLabel)) {
                    continue;
                }

                $reports[$recordName]->mergeChild(
                    $mainLabel,
                    $this->storedLabel($subLabel),
                    $metrics,
                );
            }
        }

        foreach ($this->interactionRows($event, $timezone) as $source) {
            $metrics = ['nb_interactions' => $source['nb_interactions']];

            foreach (self::RECORDS as $recordName => [$mainDimension, $subDimension]) {
                $mainLabel = $this->storedLabel($source[$mainDimension]);

                if (! $reports[$recordName]->hasRoot($mainLabel)) {
                    continue;
                }

                $reports[$recordName]->mergeRoot($mainLabel, $metrics);
                $subLabel = $source[$subDimension];

                if ($this->emptyLabel($subLabel)) {
                    continue;
                }

                $reports[$recordName]->mergeChild(
                    $mainLabel,
                    $this->storedLabel($subLabel),
                    $metrics,
                );
            }
        }

        return $reports;
    }

    /** @return array<string, HierarchicalArchiveTable> */
    private function parentReports(ArchiveReportsCollecting $event): array
    {
        $reports = $this->emptyReports();
        $children = $this->subperiods->children($event->period);
        $segmentHash = $this->segments->resolve($event->request->segment);

        foreach (array_keys(self::RECORDS) as $recordName) {
            $archives = $this->blobs->records(
                [$event->request->siteId],
                $children,
                $segmentHash,
                $recordName,
                true,
            )[$event->request->siteId] ?? [];

            foreach ($children as $child) {
                $records = $archives[$child->rangeKey()] ?? [];

                foreach ($records[$recordName] ?? [] as $root) {
                    $label = $this->archiveLabel($root['columns']['label'] ?? null);

                    if ($label === null) {
                        continue;
                    }

                    $reports[$recordName]->mergeRoot($label, $root['columns']);
                    $subtableId = $root['subtableId'];

                    if ($subtableId === null) {
                        continue;
                    }

                    foreach ($records[$recordName.'_'.$subtableId] ?? [] as $subrow) {
                        $subLabel = $this->archiveLabel($subrow['columns']['label'] ?? null);

                        if ($subLabel !== null) {
                            $reports[$recordName]->mergeChild(
                                $label,
                                $subLabel,
                                $subrow['columns'],
                            );
                        }
                    }
                }
            }
        }

        return $reports;
    }

    /** @return iterable<ImpressionRow> */
    private function impressionRows(ArchiveReportsCollecting $event, string $timezone): iterable
    {
        $query = $this->baseQuery($event, $timezone)
            ->join('log_action as content_target', static function (JoinClause $join): void {
                $join->on(
                    'content_target.idaction',
                    '=',
                    'log_link_visit_action.idaction_content_target',
                );
            })
            ->whereNotNull('log_link_visit_action.idaction_content_name')
            ->whereNull('log_link_visit_action.idaction_content_interaction')
            ->addSelect([
                'content_piece.name as content_piece',
                'content_name.name as content_name',
            ])
            ->addSelect($this->impressionMetricSelect())
            ->groupBy([
                'log_link_visit_action.idaction_content_piece',
                'log_link_visit_action.idaction_content_target',
                'log_link_visit_action.idaction_content_name',
                'content_piece.name',
                'content_name.name',
            ])
            ->orderByDesc('nb_visits')
            ->orderBy('content_name.name');
        $summary = null;
        $position = 0;

        foreach ($query->cursor() as $result) {
            $row = $this->impressionRow($result);

            if ($row === null) {
                continue;
            }

            if ($position++ < self::RANKING_QUERY_LIMIT) {
                yield $row;

                continue;
            }

            if ($summary === null) {
                $summary = $row;
                $summary['contentPiece'] = self::RANKING_SUMMARY;
                $summary['contentName'] = self::RANKING_SUMMARY;

                continue;
            }

            $summary['nb_visits'] = $this->numeric(
                $summary['nb_visits'] + $row['nb_visits'],
            );
            $summary['nb_impressions'] = $this->numeric(
                $summary['nb_impressions'] + $row['nb_impressions'],
            );
        }

        if ($summary !== null) {
            yield $summary;
        }
    }

    /** @return iterable<InteractionRow> */
    private function interactionRows(ArchiveReportsCollecting $event, string $timezone): iterable
    {
        $query = $this->baseQuery($event, $timezone)
            ->join('log_action as content_interaction', static function (JoinClause $join): void {
                $join->on(
                    'content_interaction.idaction',
                    '=',
                    'log_link_visit_action.idaction_content_interaction',
                );
            })
            ->whereNotNull('log_link_visit_action.idaction_content_name')
            ->whereNotNull('log_link_visit_action.idaction_content_interaction')
            ->addSelect([
                'content_piece.name as content_piece',
                'content_interaction.name as content_interaction',
                'content_name.name as content_name',
            ])
            ->addSelect($this->interactionMetricSelect())
            ->groupBy([
                'log_link_visit_action.idaction_content_piece',
                'log_link_visit_action.idaction_content_interaction',
                'log_link_visit_action.idaction_content_name',
                'content_piece.name',
                'content_interaction.name',
                'content_name.name',
            ])
            ->orderByDesc('nb_interactions');
        $summary = null;
        $position = 0;

        foreach ($query->cursor() as $result) {
            $row = $this->interactionRow($result);

            if ($row === null) {
                continue;
            }

            if ($position++ < self::RANKING_QUERY_LIMIT) {
                yield $row;

                continue;
            }

            if ($summary === null) {
                $summary = $row;
                $summary['contentPiece'] = self::RANKING_SUMMARY;
                $summary['contentInteraction'] = self::RANKING_SUMMARY;
                $summary['contentName'] = self::RANKING_SUMMARY;

                continue;
            }

            $summary['nb_interactions'] = $this->numeric(
                $summary['nb_interactions'] + $row['nb_interactions'],
            );
        }

        if ($summary !== null) {
            yield $summary;
        }
    }

    private function baseQuery(ArchiveReportsCollecting $event, string $timezone): Builder
    {
        return $this->actionQueries->make($event->request, $event->period, $timezone)
            ->join('log_action as content_piece', static function (JoinClause $join): void {
                $join->on(
                    'content_piece.idaction',
                    '=',
                    'log_link_visit_action.idaction_content_piece',
                );
            })
            ->join('log_action as content_name', static function (JoinClause $join): void {
                $join->on(
                    'content_name.idaction',
                    '=',
                    'log_link_visit_action.idaction_content_name',
                );
            });
    }

    private function impressionMetricSelect(): TrustedSegmentSqlExpression
    {
        $grammar = $this->connection->getQueryGrammar();
        $idVisitor = $grammar->wrap('log_visit.idvisitor');
        $idVisit = $grammar->wrap('log_link_visit_action.idvisit');

        return new TrustedSegmentSqlExpression(implode(', ', [
            "COUNT(DISTINCT {$idVisit}) AS nb_visits",
            "COUNT(DISTINCT {$idVisitor}) AS nb_uniq_visitors",
            'COUNT(*) AS nb_impressions',
        ]));
    }

    private function interactionMetricSelect(): TrustedSegmentSqlExpression
    {
        return new TrustedSegmentSqlExpression('COUNT(*) AS nb_interactions');
    }

    /** @return ImpressionRow|null */
    private function impressionRow(stdClass $result): ?array
    {
        $piece = $this->text($result->content_piece ?? null);
        $name = $this->text($result->content_name ?? null);

        if ($piece === null || $name === null) {
            return null;
        }

        return [
            'contentPiece' => $piece,
            'contentName' => $name,
            'nb_uniq_visitors' => $this->numeric($result->nb_uniq_visitors ?? null),
            'nb_visits' => $this->numeric($result->nb_visits ?? null),
            'nb_impressions' => $this->numeric($result->nb_impressions ?? null),
        ];
    }

    /** @return InteractionRow|null */
    private function interactionRow(stdClass $result): ?array
    {
        $piece = $this->text($result->content_piece ?? null);
        $interaction = $this->text($result->content_interaction ?? null);
        $name = $this->text($result->content_name ?? null);

        if ($piece === null || $interaction === null || $name === null) {
            return null;
        }

        return [
            'contentPiece' => $piece,
            'contentInteraction' => $interaction,
            'contentName' => $name,
            'nb_interactions' => $this->numeric($result->nb_interactions ?? null),
        ];
    }

    /** @return array<string, HierarchicalArchiveTable> */
    private function emptyReports(): array
    {
        return [
            'Contents_piece_name' => new HierarchicalArchiveTable,
            'Contents_name_piece' => new HierarchicalArchiveTable,
        ];
    }

    private function requested(ArchiveReportsCollecting $event): bool
    {
        if ($event->request->plugin !== null) {
            return $event->request->plugin === 'Contents';
        }

        return $event->request->reports === []
            || array_intersect($event->request->reports, self::REPORTS) !== [];
    }

    private function columnsExist(): bool
    {
        return $this->connection->getSchemaBuilder()->hasColumns('log_link_visit_action', [
            'idsite',
            'idvisit',
            'idaction_content_piece',
            'idaction_content_target',
            'idaction_content_name',
            'idaction_content_interaction',
            'server_time',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_action', [
            'idaction',
            'name',
        ]) && $this->connection->getSchemaBuilder()->hasColumns('log_visit', [
            'idsite',
            'idvisit',
            'idvisitor',
        ]);
    }

    private function impressionMainLabel(string $label, string $dimension): int|string
    {
        if ($label === self::RANKING_SUMMARY) {
            return -1;
        }

        if ($dimension === 'contentPiece' && $this->emptyLabel($label)) {
            return self::CONTENT_PIECE_NOT_SET;
        }

        return $label;
    }

    private function storedLabel(string $label): int|string
    {
        return $label === self::RANKING_SUMMARY ? -1 : $label;
    }

    private function emptyLabel(string $label): bool
    {
        return $label === '' || $label === '0';
    }

    private function archiveLabel(mixed $label): float|int|string|null
    {
        return is_float($label) || is_int($label) || is_string($label) ? $label : null;
    }

    private function text(mixed $value): ?string
    {
        return is_float($value) || is_int($value) || is_string($value)
            ? (string) $value
            : null;
    }

    private function numeric(mixed $value): int|float
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $number = round((float) $value, 2);

        return floor($number) === $number ? (int) $number : $number;
    }
}
