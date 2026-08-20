<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

final readonly class DatabaseVisitRecorder implements VisitRecorder
{
    /** @var array<string, int> */
    private const array URL_PREFIXES = [
        'http://www.' => 1,
        'http://' => 0,
        'https://www.' => 3,
        'https://' => 2,
    ];

    public function __construct(
        private ConnectionInterface $connection,
        private int $visitStandardLength = 1_800,
    ) {}

    public function record(TrackingRequest $request): void
    {
        $visitor = hex2bin($request->visitorId);
        $ip = inet_pton($request->ipAddress);
        if ($visitor === false || $ip === false) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $this->connection->transaction(function () use ($request, $visitor, $ip, $now): void {
            if ($request->actionType === 8) {
                $urlId = null;
                $nameId = $this->action($request->actionName, 8, null);
            } else {
                [$urlName, $urlPrefix] = in_array($request->actionType, [1, 10], true)
                    ? $this->normalizedUrl($request->url)
                    : [$request->url, null];
                $urlId = $this->action($urlName, $request->actionType, $urlPrefix);
                $nameId = $request->actionType === 1 && $request->actionName !== ''
                    ? $this->action($request->actionName, 4, null)
                    : null;
            }

            $visit = $this->recentVisit($request->siteId, $visitor, $now);
            $visitId = $visit === null
                ? $this->createVisit($request, $visitor, $ip, $now, $urlId, $nameId)
                : (int) $visit->idvisit;
            $position = $visit === null ? 1 : max(1, (int) $visit->visit_total_actions + 1);
            $totalEvents = ($visit === null ? 0 : (int) $visit->visit_total_events)
                + ($request->actionType === 10 ? 1 : 0);
            $totalSearches = ($visit === null ? 0 : (int) $visit->visit_total_searches)
                + ($request->actionType === 8 ? 1 : 0);
            $previousUrlId = $visit === null ? 0 : (int) ($visit->visit_exit_idaction_url ?? 0);
            $previousNameId = $visit === null ? null : $this->nullableInteger($visit->visit_exit_idaction_name ?? null);
            $secondsSincePreviousAction = $visit === null
                ? 0
                : max(0, $now->diffInSeconds(CarbonImmutable::parse($visit->visit_last_action_time, 'UTC'), true));

            $action = [
                'idsite' => $request->siteId,
                'idvisitor' => $visitor,
                'idvisit' => $visitId,
                'idaction_url' => $urlId,
                'idaction_name' => $nameId,
                'idaction_url_ref' => $previousUrlId,
                'idaction_name_ref' => $previousNameId,
                'server_time' => $now->format('Y-m-d H:i:s'),
                'pageview_position' => $position,
                'time_spent_ref_action' => $secondsSincePreviousAction,
            ];
            if ($request->eventCategory !== null && $request->eventAction !== null) {
                $action['idaction_event_category'] = $this->action($request->eventCategory, 10, null);
                $action['idaction_event_action'] = $this->action($request->eventAction, 11, null);
                $action['idaction_event_name'] = $request->eventName === null
                    ? null
                    : $this->action($request->eventName, 12, null);
                $action['custom_float'] = $request->eventValue;
            }

            if ($request->actionType === 8) {
                $action['search_cat'] = $request->searchCategory;
                $action['search_count'] = $request->searchCount;
            }

            if ($request->actionType === 13 && $request->contentName !== null) {
                $action['idaction_content_name'] = $this->action($request->contentName, 13, null);
                $action['idaction_content_piece'] = $request->contentPiece === null
                    ? null
                    : $this->action($request->contentPiece, 14, null);
                $action['idaction_content_target'] = $request->contentTarget === null
                    ? null
                    : $this->action($request->contentTarget, 15, null);
                $action['idaction_content_interaction'] = $request->contentInteraction === null
                    ? null
                    : $this->action($request->contentInteraction, 16, null);
            }

            $linkId = (int) $this->connection->table('log_link_visit_action')->insertGetId(
                [...$action, ...$request->actionProperties, ...$request->performanceTimings],
                'idlink_va',
            );

            $this->updateVisit(
                $visitId,
                $visit,
                $now,
                $urlId,
                $nameId,
                $position,
                $totalEvents,
                $totalSearches,
                $linkId,
                $request->userId,
                $request->visitProperties,
            );
        });
    }

    private function recentVisit(int $siteId, string $visitor, CarbonImmutable $now): ?stdClass
    {
        $visit = $this->connection->table('log_visit')
            ->select([
                'idvisit',
                'visit_first_action_time',
                'visit_last_action_time',
                'visit_total_actions',
                'visit_total_events',
                'visit_total_searches',
                'visit_exit_idaction_url',
                'visit_exit_idaction_name',
            ])
            ->where('idsite', $siteId)
            ->where('idvisitor', $visitor)
            ->where(
                'visit_last_action_time',
                '>=',
                $now->subSeconds($this->visitStandardLength)->format('Y-m-d H:i:s'),
            )
            ->orderByDesc('visit_last_action_time')
            ->orderByDesc('idvisit')
            ->lockForUpdate()
            ->first();

        return $visit instanceof stdClass ? $visit : null;
    }

    private function createVisit(
        TrackingRequest $request,
        string $visitor,
        string $ip,
        CarbonImmutable $now,
        ?int $urlId,
        ?int $nameId,
    ): int {
        $timestamp = $now->format('Y-m-d H:i:s');

        return (int) $this->connection->table('log_visit')->insertGetId([
            'idsite' => $request->siteId,
            'idvisitor' => $visitor,
            'visit_first_action_time' => $timestamp,
            'visit_last_action_time' => $timestamp,
            'config_id' => substr(hash('sha256', $request->visitorId.$request->userAgent, true), 0, 8),
            'location_ip' => $ip,
            'visit_entry_idaction_url' => $urlId,
            'visit_entry_idaction_name' => $nameId,
            'visit_exit_idaction_url' => $urlId,
            'visit_exit_idaction_name' => $nameId,
            'visit_total_actions' => 1,
            'visit_total_events' => $request->actionType === 10 ? 1 : 0,
            'visit_total_searches' => $request->actionType === 8 ? 1 : 0,
            'visit_total_interactions' => 1,
            'visit_total_time' => 0,
            'user_id' => $request->userId,
            'referer_url' => $request->referrerUrl,
            'referer_type' => $request->referrerType,
            'referer_name' => $request->referrerName,
            'referer_keyword' => $request->referrerKeyword,
            'location_browser_lang' => $request->browserLanguage,
            'visitor_localtime' => $request->localTime,
            'config_resolution' => $request->resolution,
            'config_cookie' => $request->cookiesEnabled ? 1 : 0,
            ...$request->visitProperties,
        ], 'idvisit');
    }

    /** @param array<string, string> $visitProperties */
    private function updateVisit(
        int $visitId,
        ?stdClass $visit,
        CarbonImmutable $now,
        ?int $urlId,
        ?int $nameId,
        int $position,
        int $totalEvents,
        int $totalSearches,
        int $linkId,
        ?string $userId,
        array $visitProperties,
    ): void {
        $firstAction = $visit === null
            ? $now
            : CarbonImmutable::parse($visit->visit_first_action_time, 'UTC');

        $updates = [
            'visit_last_action_time' => $now->format('Y-m-d H:i:s'),
            'visit_exit_idaction_url' => $urlId,
            'visit_exit_idaction_name' => $nameId,
            'visit_total_actions' => $position,
            'visit_total_events' => $totalEvents,
            'visit_total_searches' => $totalSearches,
            'visit_total_interactions' => $position,
            'visit_total_time' => max(0, $now->diffInSeconds($firstAction, true)),
            'last_idlink_va' => $linkId,
            ...$visitProperties,
        ];
        if ($userId !== null) {
            $updates['user_id'] = $userId;
        }

        $this->connection->table('log_visit')->where('idvisit', $visitId)->update($updates);
    }

    /** @return array{string, int|null} */
    private function normalizedUrl(string $url): array
    {
        $lowercaseUrl = strtolower($url);
        foreach (self::URL_PREFIXES as $prefix => $id) {
            if (str_starts_with($lowercaseUrl, $prefix)) {
                return [substr($url, strlen($prefix)), $id];
            }
        }

        return [$url, null];
    }

    private function action(string $name, int $type, ?int $urlPrefix): int
    {
        $hash = (int) sprintf('%u', crc32($name));
        $query = fn () => $this->connection->table('log_action')
            ->where(['type' => $type, 'hash' => $hash, 'name' => $name])
            ->orderBy('idaction');
        $id = $query()->value('idaction');
        if (is_numeric($id)) {
            return (int) $id;
        }

        $insertedId = (int) $this->connection->table('log_action')->insertGetId([
            'name' => $name,
            'hash' => $hash,
            'type' => $type,
            'url_prefix' => $urlPrefix,
        ], 'idaction');
        $firstId = $query()->value('idaction');
        if (is_numeric($firstId) && (int) $firstId !== $insertedId) {
            $this->connection->table('log_action')->where('idaction', $insertedId)->delete();

            return (int) $firstId;
        }

        return $insertedId;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
