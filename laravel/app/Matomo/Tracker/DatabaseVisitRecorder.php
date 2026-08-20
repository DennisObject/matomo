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

        $now = $request->recordedAt ?? CarbonImmutable::now('UTC');
        $this->connection->transaction(function () use ($request, $visitor, $ip, $now): void {
            if ($request->heartbeat) {
                $this->recordHeartbeat($request->siteId, $visitor, $now);

                return;
            }

            if ($request->goalId !== null) {
                $this->recordManualGoal($request, $visitor, $ip, $now);

                return;
            }

            if ($request->ecommerceOrderId !== null || $request->ecommerceCart) {
                $this->recordEcommerce($request, $visitor, $ip, $now);

                return;
            }

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
            if ($request->automaticGoals !== []) {
                $conversions = [];
                foreach ($request->automaticGoals as $goal) {
                    $conversions[] = [
                        'idvisit' => $visitId,
                        'idsite' => $request->siteId,
                        'idvisitor' => $visitor,
                        'server_time' => $now->format('Y-m-d H:i:s'),
                        'idaction_url' => $urlId,
                        'idlink_va' => $linkId,
                        'idgoal' => $goal['id'],
                        'buster' => $goal['allowMultiple'] ? random_int(1, 4_294_967_295) : 0,
                        'url' => $request->url,
                        'revenue' => $goal['revenue'],
                        ...$request->visitProperties,
                    ];
                }

                $this->connection->table('log_conversion')->insertOrIgnore($conversions);
                $this->connection->table('log_visit')->where('idvisit', $visitId)->update([
                    'visit_goal_converted' => 1,
                ]);
            }
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
                'visit_total_time',
                'visit_goal_buyer',
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

    private function recordHeartbeat(int $siteId, string $visitor, CarbonImmutable $now): void
    {
        $visit = $this->recentVisit($siteId, $visitor, $now);
        if ($visit === null) {
            return;
        }

        $firstAction = CarbonImmutable::parse($visit->visit_first_action_time, 'UTC');
        $totalTime = max(
            (int) $visit->visit_total_time,
            (int) $now->diffInSeconds($firstAction, true),
        );

        $this->connection->table('log_visit')
            ->where('idvisit', (int) $visit->idvisit)
            ->update(['visit_total_time' => $totalTime]);
    }

    private function recordManualGoal(
        TrackingRequest $request,
        string $visitor,
        string $ip,
        CarbonImmutable $now,
    ): void {
        $visitId = $this->conversionVisit(
            $request,
            $visitor,
            $ip,
            $now,
            ['visit_goal_converted' => 1],
        );
        $this->connection->table('log_conversion')->insertOrIgnore([
            'idvisit' => $visitId,
            'idsite' => $request->siteId,
            'idvisitor' => $visitor,
            'server_time' => $now->format('Y-m-d H:i:s'),
            'idgoal' => $request->goalId,
            'buster' => $request->goalAllowsMultiple ? random_int(1, 4_294_967_295) : 0,
            'url' => $request->url,
            'revenue' => $request->goalRevenue,
            ...$request->visitProperties,
        ]);
    }

    private function recordEcommerce(
        TrackingRequest $request,
        string $visitor,
        string $ip,
        CarbonImmutable $now,
    ): void {
        $visitId = $this->conversionVisit(
            $request,
            $visitor,
            $ip,
            $now,
            $request->ecommerceCart
                ? ['visit_goal_converted' => 1]
                : ['visit_goal_converted' => 1, 'visit_goal_buyer' => 1],
            $request->ecommerceCart,
        );
        $orderId = $request->ecommerceOrderId;
        if ($orderId === null && ! $request->ecommerceCart) {
            return;
        }

        $conversion = [
            'idvisit' => $visitId,
            'idsite' => $request->siteId,
            'idvisitor' => $visitor,
            'server_time' => $now->format('Y-m-d H:i:s'),
            'idgoal' => $request->ecommerceCart ? -1 : 0,
            'buster' => $request->ecommerceCart ? 0 : (int) base_convert(substr(md5((string) $orderId), 0, 8), 16, 10),
            'idorder' => $orderId,
            'url' => $request->url,
            'revenue' => $request->goalRevenue,
            'revenue_subtotal' => $request->ecommerceSubtotal,
            'revenue_tax' => $request->ecommerceTax,
            'revenue_shipping' => $request->ecommerceShipping,
            'revenue_discount' => $request->ecommerceDiscount,
            'items' => array_sum(array_column($request->ecommerceItems, 'quantity')),
            ...$request->visitProperties,
        ];
        if ($request->ecommerceCart) {
            $this->connection->table('log_conversion')->updateOrInsert(
                ['idvisit' => $visitId, 'idgoal' => -1, 'buster' => 0],
                $conversion,
            );
            $inserted = 1;
        } else {
            $inserted = $this->connection->table('log_conversion')->insertOrIgnore($conversion);
        }

        if ($inserted > 0 && ($request->ecommerceCart || $request->ecommerceItems !== [])) {
            $this->recordEcommerceItems(
                $request,
                $visitId,
                $visitor,
                $now,
                $request->ecommerceCart ? '0' : (string) $orderId,
                $request->ecommerceCart,
            );
        }
    }

    /** @param array<string, int> $conversionUpdates */
    private function conversionVisit(
        TrackingRequest $request,
        string $visitor,
        string $ip,
        CarbonImmutable $now,
        array $conversionUpdates,
        bool $opensCart = false,
    ): int {
        $visit = $this->recentVisit($request->siteId, $visitor, $now);
        $visitId = $visit === null
            ? $this->createVisit($request, $visitor, $ip, $now, null, null, false)
            : (int) $visit->idvisit;
        $firstAction = $visit === null
            ? $now
            : CarbonImmutable::parse($visit->visit_first_action_time, 'UTC');
        $totalTime = max(
            $visit === null ? 0 : (int) $visit->visit_total_time,
            (int) $now->diffInSeconds($firstAction, true),
        );
        $visitUpdates = [
            'visit_last_action_time' => $now->format('Y-m-d H:i:s'),
            'visit_total_time' => $totalTime,
            ...$conversionUpdates,
            ...$request->visitProperties,
        ];
        if ($opensCart) {
            $buyer = $visit === null ? 0 : (int) ($visit->visit_goal_buyer ?? 0);
            $visitUpdates['visit_goal_buyer'] = in_array($buyer, [1, 3], true) ? 3 : 2;
        }

        if ($request->userId !== null) {
            $visitUpdates['user_id'] = $request->userId;
        }

        $this->connection->table('log_visit')->where('idvisit', $visitId)->update($visitUpdates);

        return $visitId;
    }

    private function createVisit(
        TrackingRequest $request,
        string $visitor,
        string $ip,
        CarbonImmutable $now,
        ?int $urlId,
        ?int $nameId,
        bool $recordsAction = true,
    ): int {
        $timestamp = $now->format('Y-m-d H:i:s');

        return (int) $this->connection->table('log_visit')->insertGetId([
            'idsite' => $request->siteId,
            'idvisitor' => $visitor,
            'visit_first_action_time' => $timestamp,
            'visit_last_action_time' => $timestamp,
            'config_id' => $request->device?->configId !== null && $request->device->configId !== ''
                ? $request->device->configId
                : substr(hash('sha256', $request->visitorId.$request->userAgent, true), 0, 8),
            'location_ip' => $ip,
            'visit_entry_idaction_url' => $urlId,
            'visit_entry_idaction_name' => $nameId,
            'visit_exit_idaction_url' => $urlId,
            'visit_exit_idaction_name' => $nameId,
            'visit_total_actions' => $recordsAction ? 1 : 0,
            'visit_total_events' => $recordsAction && $request->actionType === 10 ? 1 : 0,
            'visit_total_searches' => $recordsAction && $request->actionType === 8 ? 1 : 0,
            'visit_total_interactions' => $recordsAction ? 1 : 0,
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
            ...($request->device?->visitColumns() ?? []),
            ...($request->location?->visitColumns() ?? []),
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

    private function recordEcommerceItems(
        TrackingRequest $request,
        int $visitId,
        string $visitor,
        CarbonImmutable $now,
        string $orderId,
        bool $cart,
    ): void {
        if ($cart) {
            $this->connection->table('log_conversion_item')
                ->where('idvisit', $visitId)
                ->where('idorder', $orderId)
                ->update(['deleted' => 1]);
        }

        $rows = [];
        $actionIds = [];
        foreach ($request->ecommerceItems as $item) {
            $categories = array_pad($item['categories'], 5, '');
            $rows[] = [
                'idsite' => $request->siteId,
                'idvisitor' => $visitor,
                'server_time' => $now->format('Y-m-d H:i:s'),
                'idvisit' => $visitId,
                'idorder' => $orderId,
                'idaction_sku' => $this->ecommerceAction($actionIds, $item['sku'], 5),
                'idaction_name' => $this->ecommerceAction($actionIds, $item['name'], 6),
                'idaction_category' => $this->ecommerceAction($actionIds, $categories[0], 7),
                'idaction_category2' => $this->ecommerceAction($actionIds, $categories[1], 7),
                'idaction_category3' => $this->ecommerceAction($actionIds, $categories[2], 7),
                'idaction_category4' => $this->ecommerceAction($actionIds, $categories[3], 7),
                'idaction_category5' => $this->ecommerceAction($actionIds, $categories[4], 7),
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'deleted' => 0,
            ];
        }

        if ($cart) {
            foreach ($rows as $row) {
                $this->connection->table('log_conversion_item')->updateOrInsert(
                    [
                        'idvisit' => $visitId,
                        'idorder' => $orderId,
                        'idaction_sku' => $row['idaction_sku'],
                    ],
                    $row,
                );
            }
        } elseif ($rows !== []) {
            $this->connection->table('log_conversion_item')->insertOrIgnore($rows);
        }
    }

    /** @param array<string, int> $actionIds */
    private function ecommerceAction(array &$actionIds, string $name, int $type): int
    {
        if ($name === '') {
            return 0;
        }

        $key = $type."\0".$name;

        return $actionIds[$key] ??= $this->action($name, $type, 0);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
