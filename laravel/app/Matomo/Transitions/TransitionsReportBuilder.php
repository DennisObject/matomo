<?php

declare(strict_types=1);

namespace App\Matomo\Transitions;

use App\Matomo\Api\TransitionsRequest;
use App\Matomo\Archiving\ActionArchivePathResolver;
use App\Matomo\Archiving\ArchiveActionQueryFactory;
use App\Matomo\Archiving\ArchiveReportRequest;
use App\Matomo\Archiving\ArchiveVisitQueryFactory;
use App\Matomo\Archiving\TrustedSegmentSqlExpression;
use App\Matomo\Database\MatomoDatabase;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Reporting\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;

final readonly class TransitionsReportBuilder
{
    private const int DEFAULT_LIMIT = 5;

    private const int REFERRER_DIRECT = 1;

    private const int REFERRER_SEARCH = 2;

    private const int REFERRER_WEBSITE = 3;

    private const int REFERRER_CAMPAIGN = 6;

    private const int REFERRER_SOCIAL = 7;

    private const int REFERRER_AI = 8;

    private Connection $connection;

    public function __construct(
        MatomoDatabase $database,
        private ArchiveActionQueryFactory $actionQueries,
        private ArchiveVisitQueryFactory $visitQueries,
        private ActionArchivePathResolver $paths,
        private MatomoTranslator $translator,
    ) {
        $this->connection = $database->connection();
    }

    /**
     * @param  non-empty-list<ReportingPeriod>  $periods
     * @return array<string, mixed>
     */
    public function build(
        TransitionsRequest $request,
        array $periods,
        string $timezone,
        string $language,
    ): array {
        $actionName = $request->actionName
            ?? throw new InvalidArgumentException('No action name was provided.');
        $actionType = $request->actionType
            ?? throw new InvalidArgumentException('Unknown action type');
        $idAction = $this->actionId($actionName, $actionType, $language);

        if ($idAction === null) {
            throw new InvalidArgumentException('NoDataForAction');
        }

        $period = $this->combinedPeriod($periods, $request->period);
        $archiveRequest = new ArchiveReportRequest(
            siteId: $request->siteId,
            period: $request->period,
            date: $request->date,
            segment: $request->segment,
        );
        $report = ['date' => $this->periodLabel($period, $language)];
        $followingTransitions = 0;

        if ($request->allParts || in_array('internalReferrers', $request->parts, true)) {
            $internal = $this->internalReferrers(
                $archiveRequest,
                $period,
                $timezone,
                $idAction,
                $actionType,
                $request->limitBeforeGrouping,
                $language,
            );

            if ($internal['pageviews'] === 0) {
                throw new InvalidArgumentException('NoDataForAction');
            }

            $report['previousPages'] = $internal['previousPages'];
            $report['previousSiteSearches'] = $internal['previousSiteSearches'];
            $report['pageMetrics'] = [
                'loops' => $internal['loops'],
                'pageviews' => $internal['pageviews'],
            ];
        }

        if ($request->allParts || in_array('followingActions', $request->parts, true)) {
            $following = $this->followingActions(
                $archiveRequest,
                $period,
                $timezone,
                $idAction,
                $actionType,
                $request->limitBeforeGrouping,
                ! $request->allParts
                    && ! in_array('internalReferrers', $request->parts, true),
                $language,
            );

            foreach ($following['tables'] as $name => $rows) {
                $report[$name] = $rows;
            }

            $followingTransitions = $following['internalTransitions'];
        }

        if ($request->allParts || in_array('externalReferrers', $request->parts, true)) {
            $external = $this->externalReferrers(
                $archiveRequest,
                $period,
                $timezone,
                $idAction,
                $actionType,
                $request->limitBeforeGrouping,
                $language,
            );
            $metrics = is_array($report['pageMetrics'] ?? null) ? $report['pageMetrics'] : [];
            $metrics['entries'] = $external['entries'];
            $report['pageMetrics'] = $metrics;
            $report['referrers'] = $external['referrers'];
        }

        if ($request->allParts) {
            /** @var array<string, int> $metrics */
            $metrics = $report['pageMetrics'];
            $metrics['exits'] = $metrics['pageviews'] - $followingTransitions - $metrics['loops'];
            $report['pageMetrics'] = $metrics;
        }

        return $report;
    }

    /**
     * @return array{
     *     pageviews: int,
     *     loops: int,
     *     previousPages: list<array{label: string, referrals: int}>,
     *     previousSiteSearches: list<array{label: string, referrals: int}>
     * }
     */
    private function internalReferrers(
        ArchiveReportRequest $request,
        ReportingPeriod $period,
        string $timezone,
        int $idAction,
        string $actionType,
        int $limit,
        string $language,
    ): array {
        $currentColumn = $actionType === 'title' ? 'idaction_name' : 'idaction_url';
        $grammar = $this->connection->getQueryGrammar();
        $previousDimension = $actionType === 'title'
            ? $grammar->wrap('log_link_visit_action.idaction_name_ref')
            : sprintf(
                'COALESCE(%s, %s)',
                $grammar->wrap('log_link_visit_action.idaction_url_ref'),
                $grammar->wrap('log_link_visit_action.idaction_name_ref'),
            );
        $query = $this->actionQueries->make($request, $period, $timezone)
            ->leftJoin('log_action as transition_previous', static function (JoinClause $join) use ($previousDimension): void {
                $join->on(
                    'transition_previous.idaction',
                    '=',
                    new TrustedSegmentSqlExpression($previousDimension),
                );
            })
            ->where('log_link_visit_action.'.$currentColumn, $idAction)
            ->select([
                'transition_previous.name as previous_name',
                'transition_previous.url_prefix as previous_prefix',
                'transition_previous.type as previous_type',
            ])
            ->addSelect(new TrustedSegmentSqlExpression($previousDimension.' AS previous_id'))
            ->addSelect(new TrustedSegmentSqlExpression('COUNT(*) AS referrals'))
            ->groupBy([
                new TrustedSegmentSqlExpression($previousDimension),
                'transition_previous.name',
                'transition_previous.url_prefix',
                'transition_previous.type',
            ])
            ->orderByDesc('referrals')
            ->orderBy('transition_previous.name');
        $pageviews = 0;
        $loops = 0;
        $pages = ['values' => [], 'other' => 0];
        $searches = ['values' => [], 'other' => 0];

        foreach ($query->cursor() as $record) {
            $row = (array) $record;
            $referrals = $this->integer($row, 'referrals');
            $pageviews += $referrals;
            $referenceId = $this->nullableInteger($row, 'previous_id');

            if ($referenceId === $idAction) {
                $loops += $referrals;

                continue;
            }

            $type = $this->nullableInteger($row, 'previous_type');
            $name = $this->nullableString($row, 'previous_name');
            $prefix = $this->nullableInteger($row, 'previous_prefix');

            if ($type === ActionArchivePathResolver::PAGE_URL
                || $type === ActionArchivePathResolver::PAGE_TITLE) {
                $label = $this->actionLabel($name, $type, $prefix, $language);
                $this->addRankedValue($pages, $label, $referrals, $limit);
            } elseif ($type === ActionArchivePathResolver::SITE_SEARCH) {
                $label = $name ?? '';
                $this->addRankedValue($searches, $label, $referrals, $limit);
            }
        }

        return [
            'pageviews' => $pageviews,
            'loops' => $loops,
            'previousPages' => $this->bucketRows($pages, $language),
            'previousSiteSearches' => $this->bucketRows($searches, $language),
        ];
    }

    /**
     * @return array{
     *     tables: array<string, list<array{label: string, referrals: int}>>,
     *     internalTransitions: int
     * }
     */
    private function followingActions(
        ArchiveReportRequest $request,
        ReportingPeriod $period,
        string $timezone,
        int $idAction,
        string $actionType,
        int $limit,
        bool $includeLoops,
        string $language,
    ): array {
        $referenceColumn = $actionType === 'title' ? 'idaction_name_ref' : 'idaction_url_ref';
        $currentColumn = $actionType === 'title' ? 'idaction_name' : 'idaction_url';
        $grammar = $this->connection->getQueryGrammar();
        $currentUrlId = $grammar->wrap('log_link_visit_action.idaction_url');
        $urlName = $grammar->wrap('transition_following_url.name');
        $urlPrefix = $grammar->wrap('transition_following_url.url_prefix');
        $urlType = $grammar->wrap('transition_following_url.type');
        $nameName = $grammar->wrap('transition_following_name.name');
        $namePrefix = $grammar->wrap('transition_following_name.url_prefix');
        $nameType = $grammar->wrap('transition_following_name.type');
        $pageChoice = $actionType === 'title'
            ? "{$currentUrlId} IS NULL OR {$urlType} = ".ActionArchivePathResolver::PAGE_URL
            : "{$currentUrlId} IS NULL";
        $followingName = "CASE WHEN {$pageChoice} THEN {$nameName} ELSE {$urlName} END";
        $followingPrefix = "CASE WHEN {$pageChoice} THEN {$namePrefix} ELSE {$urlPrefix} END";
        $followingType = "CASE WHEN {$pageChoice} THEN {$nameType} ELSE {$urlType} END";
        $query = $this->actionQueries->make($request, $period, $timezone)
            ->leftJoin('log_action as transition_following_url', static function (JoinClause $join): void {
                $join->on(
                    'transition_following_url.idaction',
                    '=',
                    'log_link_visit_action.idaction_url',
                );
            })
            ->leftJoin('log_action as transition_following_name', static function (JoinClause $join): void {
                $join->on(
                    'transition_following_name.idaction',
                    '=',
                    'log_link_visit_action.idaction_name',
                );
            })
            ->where('log_link_visit_action.'.$referenceColumn, $idAction);

        if (! $includeLoops) {
            $query->where(static function (Builder $loop) use ($currentColumn, $idAction): void {
                $loop->whereNull('log_link_visit_action.'.$currentColumn)
                    ->orWhere('log_link_visit_action.'.$currentColumn, '!=', $idAction);
            });
        }

        $records = $query
            ->select(new TrustedSegmentSqlExpression($followingName.' AS following_name'))
            ->addSelect(new TrustedSegmentSqlExpression($followingPrefix.' AS following_prefix'))
            ->addSelect(new TrustedSegmentSqlExpression($followingType.' AS following_type'))
            ->addSelect(new TrustedSegmentSqlExpression('COUNT(*) AS referrals'))
            ->groupBy([
                new TrustedSegmentSqlExpression($followingName),
                new TrustedSegmentSqlExpression($followingPrefix),
                new TrustedSegmentSqlExpression($followingType),
            ])
            ->orderByDesc('referrals')
            ->orderBy('following_name')
            ->cursor();
        /** @var array<string, array{values: array<string, int>, other: int}> $byTable */
        $byTable = [
            'followingPages' => ['values' => [], 'other' => 0],
            'followingSiteSearches' => ['values' => [], 'other' => 0],
            'outlinks' => ['values' => [], 'other' => 0],
            'downloads' => ['values' => [], 'other' => 0],
        ];
        $internalTransitions = 0;

        foreach ($records as $record) {
            $row = (array) $record;
            $referrals = $this->integer($row, 'referrals');
            $type = $this->nullableInteger($row, 'following_type');
            $name = $this->nullableString($row, 'following_name');
            $prefix = $this->nullableInteger($row, 'following_prefix');
            $table = match ($type) {
                ActionArchivePathResolver::PAGE_URL,
                ActionArchivePathResolver::PAGE_TITLE => 'followingPages',
                ActionArchivePathResolver::SITE_SEARCH => 'followingSiteSearches',
                ActionArchivePathResolver::OUTLINK => 'outlinks',
                ActionArchivePathResolver::DOWNLOAD => 'downloads',
                default => null,
            };

            if ($table === null) {
                continue;
            }

            $label = $this->actionLabel($name, $type, $prefix, $language);
            $this->addRankedValue($byTable[$table], $label, $referrals, $limit);

            if (in_array($type, [
                ActionArchivePathResolver::PAGE_URL,
                ActionArchivePathResolver::PAGE_TITLE,
                ActionArchivePathResolver::SITE_SEARCH,
            ], true)) {
                $internalTransitions += $referrals;
            }
        }

        $tables = [];

        foreach ($byTable as $name => $bucket) {
            $tables[$name] = $this->bucketRows($bucket, $language);
        }

        return ['tables' => $tables, 'internalTransitions' => $internalTransitions];
    }

    /**
     * @return array{
     *     entries: int,
     *     referrers: list<array{label: string, shortName: string, visits: int, details?: list<array{label: string, referrals: int}>}>
     * }
     */
    private function externalReferrers(
        ArchiveReportRequest $request,
        ReportingPeriod $period,
        string $timezone,
        int $idAction,
        string $actionType,
        int $limit,
        string $language,
    ): array {
        $entryColumn = $actionType === 'title'
            ? 'visit_entry_idaction_name'
            : 'visit_entry_idaction_url';
        $grammar = $this->connection->getQueryGrammar();
        $referrerType = $grammar->wrap('log_visit.referer_type');
        $referrerName = $grammar->wrap('log_visit.referer_name');
        $referrerKeyword = $grammar->wrap('log_visit.referer_keyword');
        $referrerUrl = $grammar->wrap('log_visit.referer_url');
        $campaignExpression = $this->connection->getDriverName() === 'sqlite'
            ? "TRIM(COALESCE({$referrerName}, '') || ' ' || COALESCE({$referrerKeyword}, ''))"
            : "TRIM(CONCAT_WS(' ', {$referrerName}, {$referrerKeyword}))";
        $referrerExpression = implode(' ', [
            "CASE {$referrerType}",
            "WHEN 1 THEN ''",
            "WHEN 2 THEN COALESCE({$referrerName}, '')",
            "WHEN 7 THEN COALESCE({$referrerName}, '')",
            "WHEN 8 THEN COALESCE({$referrerName}, '')",
            "WHEN 3 THEN COALESCE({$referrerUrl}, '')",
            "WHEN 6 THEN {$campaignExpression}",
            "ELSE '' END",
        ]);
        $records = $this->visitQueries->make($request, $period, $timezone)
            ->where('log_visit.'.$entryColumn, $idAction)
            ->whereIn('log_visit.referer_type', $this->referrerTypes())
            ->select('log_visit.referer_type')
            ->addSelect(new TrustedSegmentSqlExpression($referrerExpression.' AS referrer_data'))
            ->addSelect(new TrustedSegmentSqlExpression('COUNT(*) AS visits'))
            ->groupBy('log_visit.referer_type')
            ->groupBy(new TrustedSegmentSqlExpression($referrerExpression))
            ->orderBy('log_visit.referer_type')
            ->orderByDesc('visits')
            ->orderBy('referrer_data')
            ->cursor();
        /** @var array<int, array{values: array<string, int>, other: int}> $details */
        $details = [];

        foreach ($records as $record) {
            $row = (array) $record;
            $type = $this->nullableInteger($row, 'referer_type');

            if (! in_array($type, $this->referrerTypes(), true)) {
                continue;
            }

            $label = $this->nullableString($row, 'referrer_data') ?? '';

            if ($type === self::REFERRER_SEARCH && $label === '') {
                $label = $this->translator->translate('General_Unknown', $language);
            }

            $details[$type] ??= ['values' => [], 'other' => 0];
            $this->addRankedValue($details[$type], $label, $this->integer($row, 'visits'), $limit);
        }

        $entries = 0;
        $referrers = [];

        foreach ($this->referrerTypes() as $type) {
            $typeDetails = $details[$type] ?? ['values' => [], 'other' => 0];
            $visits = array_sum($typeDetails['values']) + $typeDetails['other'];

            if ($visits === 0) {
                continue;
            }

            $entries += $visits;
            $row = [
                'label' => $this->referrerLabel($type, $language),
                'shortName' => $this->referrerShortName($type),
                'visits' => $visits,
                'details' => $type === self::REFERRER_DIRECT
                    ? []
                    : $this->bucketRows($typeDetails, $language),
            ];
            $referrers[] = $row;
        }

        if ($referrers === []) {
            $referrers[] = [
                'label' => $this->referrerLabel(self::REFERRER_DIRECT, $language),
                'shortName' => $this->referrerShortName(self::REFERRER_DIRECT),
                'visits' => 0,
            ];
        }

        return ['entries' => $entries, 'referrers' => $referrers];
    }

    private function actionId(string $name, string $type, string $language): ?int
    {
        if ($type === 'title') {
            $unknown = $this->translator->translate('General_NotDefined', $language, [
                $this->translator->translate('Actions_ColumnPageName', $language),
            ]);

            if (trim($name) === trim($unknown)) {
                return 0;
            }

            $id = $this->connection->table('log_action')
                ->where('type', ActionArchivePathResolver::PAGE_TITLE)
                ->where('name', $name)
                ->value('idaction');

            return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
        }

        $candidates = array_values(array_unique([
            html_entity_decode($name, ENT_QUOTES | ENT_HTML401, 'UTF-8'),
            $name,
        ]));

        foreach ($candidates as $candidate) {
            $actionName = $this->normalizedUrl($candidate);
            $id = $this->connection->table('log_action')
                ->where('type', ActionArchivePathResolver::PAGE_URL)
                ->where('name', $actionName)
                ->value('idaction');

            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        return null;
    }

    private function normalizedUrl(string $url): string
    {
        if (preg_match('@^(https?)://(www\.)?(.*)$@iD', $url, $matches) !== 1) {
            return $url;
        }

        $name = $matches[3];
        $slash = strpos($name, '/');
        $hostLength = $slash === false ? strlen($name) : $slash;
        $name = strtolower(substr($name, 0, $hostLength)).substr($name, $hostLength);

        return $name;
    }

    private function actionLabel(?string $name, ?int $type, ?int $prefix, string $language): string
    {
        if ($type === ActionArchivePathResolver::PAGE_TITLE && ($name === null || $name === '')) {
            return $this->translator->translate('General_NotDefined', $language, [
                $this->translator->translate('Actions_ColumnPageName', $language),
            ]);
        }

        if (in_array($type, [
            ActionArchivePathResolver::OUTLINK,
            ActionArchivePathResolver::DOWNLOAD,
        ], true)) {
            return $this->paths->reconstructedUrl($name ?? '', $prefix);
        }

        return $name ?? '';
    }

    /** @param array{values: array<string, int>, other: int} $bucket */
    private function addRankedValue(array &$bucket, string $label, int $value, int $requestedLimit): void
    {
        if (array_key_exists($label, $bucket['values'])) {
            $bucket['values'][$label] += $value;

            return;
        }

        $limit = $requestedLimit === 0 ? self::DEFAULT_LIMIT : $requestedLimit;

        if ($limit < 0 || count($bucket['values']) < $limit) {
            $bucket['values'][$label] = $value;

            return;
        }

        $bucket['other'] += $value;
    }

    /**
     * @param  array{values: array<string, int>, other: int}  $bucket
     * @return list<array{label: string, referrals: int}>
     */
    private function bucketRows(array $bucket, string $language): array
    {
        $values = $bucket['values'];
        $others = $this->translator->translate('General_Others', $language);
        $other = $bucket['other'] + ($values[$others] ?? 0);
        unset($values[$others]);
        uksort($values, static function (string $left, string $right) use ($values): int {
            $comparison = $values[$right] <=> $values[$left];

            return $comparison !== 0 ? $comparison : strcmp($left, $right);
        });

        $rows = [];

        foreach ($values as $label => $referrals) {
            $rows[] = ['label' => $label, 'referrals' => $referrals];
        }

        if ($other > 0) {
            $rows[] = ['label' => $others, 'referrals' => $other];
        }

        return $rows;
    }

    /** @return list<int> */
    private function referrerTypes(): array
    {
        return [
            self::REFERRER_DIRECT,
            self::REFERRER_SEARCH,
            self::REFERRER_SOCIAL,
            self::REFERRER_AI,
            self::REFERRER_WEBSITE,
            self::REFERRER_CAMPAIGN,
        ];
    }

    private function referrerLabel(int $type, string $language): string
    {
        $key = match ($type) {
            self::REFERRER_DIRECT => 'Transitions_DirectEntries',
            self::REFERRER_SEARCH => 'Transitions_FromSearchEngines',
            self::REFERRER_SOCIAL => 'Transitions_FromSocialNetworks',
            self::REFERRER_AI => 'Transitions_FromAIAssistants',
            self::REFERRER_WEBSITE => 'Transitions_FromWebsites',
            self::REFERRER_CAMPAIGN => 'Transitions_FromCampaigns',
            default => 'General_Others',
        };

        return $this->translator->translate($key, $language);
    }

    private function referrerShortName(int $type): string
    {
        return match ($type) {
            self::REFERRER_DIRECT => 'direct',
            self::REFERRER_SEARCH => 'search',
            self::REFERRER_SOCIAL => 'social',
            self::REFERRER_AI => 'ai',
            self::REFERRER_WEBSITE => 'website',
            self::REFERRER_CAMPAIGN => 'campaign',
            default => 'direct',
        };
    }

    /** @param non-empty-list<ReportingPeriod> $periods */
    private function combinedPeriod(array $periods, string $label): ReportingPeriod
    {
        $first = $periods[0];
        $last = $periods[array_key_last($periods)];

        return new ReportingPeriod(
            label: count($periods) === 1 ? $first->label : $label,
            id: $first->id,
            startDate: $first->startDate,
            endDate: $last->endDate,
            resultKey: $first->startDate.','.$last->endDate,
        );
    }

    private function periodLabel(ReportingPeriod $period, string $language): string
    {
        $start = CarbonImmutable::parse($period->startDate, 'UTC')->settings(['locale' => $language]);
        $end = CarbonImmutable::parse($period->endDate, 'UTC')->settings(['locale' => $language]);

        if ($start->isSameDay($end)) {
            return $start->translatedFormat('D, M j');
        }

        if ($period->label === 'month'
            && $start->isSameMonth($end)
            && $start->isSameYear($end)) {
            return $start->translatedFormat('M Y');
        }

        if ($period->label === 'year' && $start->isSameYear($end)) {
            return $start->format('Y');
        }

        if (! $start->isSameYear($end)) {
            return $start->translatedFormat('M j, Y').' – '.$end->translatedFormat('M j, Y');
        }

        if (! $start->isSameMonth($end)) {
            return $start->translatedFormat('M j').' – '.$end->translatedFormat('M j, Y');
        }

        return $start->translatedFormat('M j').' – '.$end->translatedFormat('j, Y');
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }

    /** @param array<string, mixed> $row */
    private function nullableInteger(array $row, string $key): ?int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : null;
    }
}
