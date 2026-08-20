<?php

declare(strict_types=1);

namespace App\Matomo\Live;

use App\Matomo\Reporting\DurationFormatter;

final readonly class LiveVisitorProfileBuilder
{
    public function __construct(
        private DurationFormatter $durations,
        private int $maximumVisitsToAggregate = 100,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $visits
     * @return array<string, mixed>
     */
    public function build(string $visitorId, array $visits, int $limit): array
    {
        if ($visits === []) {
            return [];
        }

        $hasMoreVisits = count($visits) > $this->maximumVisitsToAggregate;
        $visits = array_slice($visits, 0, $this->maximumVisitsToAggregate);
        $totalDuration = 0;
        $totalActions = 0;
        $totalOutlinks = 0;
        $totalDownloads = 0;
        $totalSearches = 0;
        $totalPageViews = 0;
        $totalEvents = 0;
        $totalGoalConversions = 0;
        $pages = [];
        $searches = [];
        $conversions = [];
        $revenues = [];
        $deviceCounts = [];
        $countryCounts = [];
        $continentCounts = [];
        foreach ($visits as $visit) {
            $totalDuration += (int) ($visit['visitDuration'] ?? 0);
            $totalActions += (int) ($visit['actions'] ?? 0);
            $totalGoalConversions += (int) ($visit['goalConversions'] ?? 0);
            $deviceType = (string) ($visit['deviceType'] ?? '');
            $deviceName = trim((string) ($visit['deviceBrand'] ?? '').' '.(string) ($visit['deviceModel'] ?? ''));
            $deviceCounts[$deviceType]['count'] = ($deviceCounts[$deviceType]['count'] ?? 0) + 1;
            $deviceCounts[$deviceType]['icon'] = $visit['deviceTypeIcon'] ?? '';
            $deviceCounts[$deviceType]['devices'][$deviceName] = ($deviceCounts[$deviceType]['devices'][$deviceName] ?? 0) + 1;
            $country = (string) ($visit['countryCode'] ?? '');
            $continent = (string) ($visit['continentCode'] ?? '');
            $countryCounts[$country] = ($countryCounts[$country] ?? 0) + 1;
            $continentCounts[$continent] = ($continentCounts[$continent] ?? 0) + 1;
            foreach ($this->actions($visit) as $action) {
                $type = $action['type'] ?? null;
                $totalOutlinks += (int) ($type === 'outlink');
                $totalDownloads += (int) ($type === 'download');
                $totalSearches += (int) ($type === 'search');
                $totalEvents += (int) ($type === 'event');
                if ($type === 'action') {
                    $totalPageViews++;
                    $url = (string) ($action['url'] ?? '');
                    if ($url !== '') {
                        $pages[$url] = ($pages[$url] ?? 0) + 1;
                    }
                }

                if ($type === 'search') {
                    $keyword = (string) ($action['siteSearchKeyword'] ?? '');
                    $searches[$keyword] = ($searches[$keyword] ?? 0) + 1;
                }

                if ($type === 'goal' && ! empty($action['goalId'])) {
                    $goal = 'idgoal='.(int) $action['goalId'];
                    $conversions[$goal] = ($conversions[$goal] ?? 0) + 1;
                    $revenues[$goal] = ($revenues[$goal] ?? 0.0) + (float) ($action['revenue'] ?? 0);
                }
            }
        }

        arsort($pages);
        $visitedPages = [];
        foreach ($pages as $url => $count) {
            $visitedPages[] = ['url' => $url, 'count' => $count];
        }

        $devices = [];
        foreach ($deviceCounts as $type => $data) {
            $deviceNames = [];
            foreach ($data['devices'] as $name => $count) {
                $deviceNames[] = ['name' => $name, 'count' => $count];
            }

            $devices[] = ['type' => $type, 'count' => $data['count'], 'icon' => $data['icon'], 'devices' => $deviceNames];
        }

        $countries = [];
        foreach ($countryCounts as $country => $count) {
            $countries[] = ['country' => $country, 'nb_visits' => $count];
        }

        $continents = [];
        foreach ($continentCounts as $continent => $count) {
            $continents[] = ['continent' => $continent, 'nb_visits' => $count];
        }

        $last = $visits[0];
        $first = $visits[count($visits) - 1];

        return [
            'visitorId' => $visitorId,
            'hasMoreVisits' => $hasMoreVisits,
            'totalVisits' => count($visits),
            'totalVisitDuration' => $totalDuration,
            'totalVisitDurationPretty' => $this->durations->sentence($totalDuration),
            'totalActions' => $totalActions,
            'totalOutlinks' => $totalOutlinks,
            'totalDownloads' => $totalDownloads,
            'totalSearches' => $totalSearches,
            'totalPageViews' => $totalPageViews,
            'totalUniquePageViews' => count($pages),
            'totalRevisitedPages' => count(array_filter($pages, static fn (int $count): bool => $count > 1)),
            'totalPageViewsWithTiming' => 0,
            'totalPageViewsWithLoadTime' => 0,
            'searches' => array_map(
                static fn (string $keyword, int $count): array => ['keyword' => $keyword, 'count' => $count],
                array_keys($searches),
                array_values($searches),
            ),
            'totalGoalConversions' => $totalGoalConversions,
            'totalConversionsByGoal' => $conversions,
            'totalRevenueByGoal' => $revenues,
            'totalEvents' => $totalEvents,
            'hasLatLong' => array_any($visits, static fn (array $visit): bool => $visit['latitude'] !== null),
            'visitedPages' => $visitedPages,
            'devices' => $devices,
            'countries' => $countries,
            'continents' => $continents,
            'userId' => $last['userId'] ?? null,
            'firstVisit' => $this->summary($first),
            'lastVisit' => $this->summary($last),
            'visitsAggregated' => count($visits),
            'lastVisits' => array_slice($visits, 0, $limit),
            'nextVisitorId' => false,
            'previousVisitorId' => false,
        ];
    }

    public function maximumVisitsToFetch(): int
    {
        return $this->maximumVisitsToAggregate + 1;
    }

    /** @param array<string, mixed> $visit
     * @return list<array<string, mixed>>
     */
    private function actions(array $visit): array
    {
        $actions = $visit['actionDetails'] ?? [];

        return is_array($actions) ? array_values(array_filter($actions, is_array(...))) : [];
    }

    /** @param array<string, mixed> $visit
     * @return array<string, mixed>
     */
    private function summary(array $visit): array
    {
        return [
            'date' => $visit['firstActionTimestamp'] ?? 0,
            'referrerType' => $visit['referrerType'] ?? '',
            'referrerUrl' => $visit['referrerUrl'] ?? '',
            'referralSummary' => $visit['referrerName'] ?? '',
        ];
    }
}
