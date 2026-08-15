<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

final readonly class DatabaseVisitRecorder implements VisitRecorder
{
    public function __construct(private Connection $connection) {}

    public function record(TrackingRequest $request): void
    {
        $visitor = hex2bin($request->visitorId);
        $ip = inet_pton($request->ipAddress);
        if ($visitor === false || $ip === false) {
            return;
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $this->connection->transaction(function () use ($request, $visitor, $ip, $now): void {
            $url = $this->action($request->url, $request->actionType === 8 ? 1 : $request->actionType);
            $name = $request->actionType === 8
                ? $this->action($request->actionName, 8)
                : ($request->actionName === '' ? null : $this->action($request->actionName, 4));
            $visitId = $this->connection->table('log_visit')->where('idsite', $request->siteId)->where('idvisitor', $visitor)->where('visit_last_action_time', '>=', CarbonImmutable::parse($now)->subMinutes(30)->format('Y-m-d H:i:s'))->value('idvisit');
            if ($request->heartbeat) {
                if (is_numeric($visitId)) {
                    $firstAction = $this->connection->table('log_visit')->where('idvisit', (int) $visitId)->value('visit_first_action_time');
                    $totalTime = is_string($firstAction)
                        ? max(0, CarbonImmutable::parse($firstAction, 'UTC')->diffInSeconds(CarbonImmutable::parse($now, 'UTC')))
                        : 0;
                    $updates = $this->available('log_visit', ['visit_total_time' => $totalTime]);
                    if ($updates !== []) {
                        $this->connection->table('log_visit')->where('idvisit', (int) $visitId)->update($updates);
                    }
                }

                return;
            }

            if (! is_numeric($visitId)) {
                $visit = [
                    'idsite' => $request->siteId, 'idvisitor' => $visitor, 'visit_last_action_time' => $now,
                    'config_id' => substr(hash('sha256', $request->siteId.$request->ipAddress.$request->userAgent, true), 0, 8), 'location_ip' => $ip,
                    'visit_first_action_time' => $now, 'visit_entry_idaction_url' => $url,
                    'visit_entry_idaction_name' => $name ?? 0, 'visit_exit_idaction_url' => $url,
                    'visit_exit_idaction_name' => $name ?? 0, 'visit_total_actions' => 1,
                    'visit_total_events' => $request->actionType === 10 ? 1 : 0, 'visit_total_time' => 0,
                    'visit_total_searches' => $request->actionType === 8 ? 1 : 0,
                    'visit_goal_converted' => 0, 'visit_goal_buyer' => 0,
                    'visitor_returning' => 0, 'visitor_count_visits' => 1, 'visitor_days_since_last' => 0,
                    'visitor_days_since_first' => 0, 'visitor_days_since_order' => 0,
                    'config_windowsmedia' => 0, 'config_silverlight' => 0,
                    'config_java' => 0, 'config_pdf' => 0, 'config_quicktime' => 0, 'config_realplayer' => 0,
                    'config_flash' => 0, 'config_browser_version' => '', 'config_browser_name' => '',
                    'config_browser_engine' => '', 'config_os' => '', 'config_cookie' => $request->cookiesEnabled ? 1 : 0,
                    'location_country' => '', 'location_browser_lang' => $request->browserLanguage,
                    'visitor_localtime' => $request->localTime, 'referer_url' => $request->referrerUrl,
                    'referer_type' => $request->referrerType, 'referer_name' => $request->referrerName,
                    'referer_keyword' => $request->referrerKeyword,
                    'user_id' => $request->userId, 'config_resolution' => $request->resolution,
                ];
                $visitId = $this->connection->table('log_visit')->insertGetId(
                    $this->available('log_visit', [...$visit, ...$request->visitProperties]),
                    'idvisit',
                );
            } else {
                $updates = [
                    'visit_last_action_time' => $now, 'visit_exit_idaction_url' => $url,
                    'visit_exit_idaction_name' => $name ?? 0, 'user_id' => $request->userId,
                ];
                $query = $this->connection->table('log_visit')->where('idvisit', (int) $visitId);
                $query->update($this->available('log_visit', [...$updates, ...$request->visitProperties]));
                $query->increment('visit_total_actions');
                if ($request->actionType === 10) {
                    $query->increment('visit_total_events');
                }

                if ($request->actionType === 8) {
                    $query->increment('visit_total_searches');
                }
            }

            $action = [
                'idsite' => $request->siteId, 'idvisitor' => $visitor, 'idvisit' => (int) $visitId,
                'idaction_url' => $url, 'idaction_name' => $name, 'server_time' => $now, 'idaction_url_ref' => 0,
                'time_spent_ref_action' => 0,
            ];
            if ($request->eventCategory !== null && $request->eventAction !== null) {
                $action['idaction_event_category'] = $this->action($request->eventCategory, 10);
                $action['idaction_event_action'] = $this->action($request->eventAction, 11);
                $action['idaction_event_name'] = $request->eventName === null ? null : $this->action($request->eventName, 12);
                $action['custom_float'] = $request->eventValue;
            }

            if ($request->actionType === 8) {
                $action['search_cat'] = $request->searchCategory;
                $action['search_count'] = $request->searchCount;
            }

            if ($request->actionType === 13 && $request->contentName !== null) {
                $action['idaction_content_name'] = $this->action($request->contentName, 13);
                $action['idaction_content_piece'] = $request->contentPiece === null ? null : $this->action($request->contentPiece, 14);
                $action['idaction_content_target'] = $request->contentTarget === null ? null : $this->action($request->contentTarget, 15);
                $action['idaction_content_interaction'] = $request->contentInteraction === null ? null : $this->action($request->contentInteraction, 16);
            }

            $actionId = $this->connection->table('log_link_visit_action')->insertGetId(
                $this->available('log_link_visit_action', [
                    ...$action,
                    ...$request->actionProperties,
                    ...$request->performanceTimings,
                ]),
                'idlink_va',
            );
            if ($request->goalId !== null || $request->ecommerceOrderId !== null) {
                $buster = $request->goalAllowsMultiple ? random_int(1, 4_294_967_295) : 0;
                $conversion = [
                    'idvisit' => (int) $visitId, 'idsite' => $request->siteId, 'idvisitor' => $visitor,
                    'server_time' => $now, 'idaction_url' => $url, 'idlink_va' => (int) $actionId,
                    'idgoal' => $request->ecommerceOrderId === null ? $request->goalId : 0,
                    'buster' => $buster, 'idorder' => $request->ecommerceOrderId, 'url' => $request->url,
                    'revenue' => $request->goalRevenue,
                    'revenue_subtotal' => $request->ecommerceSubtotal,
                    'revenue_tax' => $request->ecommerceTax,
                    'revenue_shipping' => $request->ecommerceShipping,
                    'revenue_discount' => $request->ecommerceDiscount,
                    'items' => array_sum(array_column($request->ecommerceItems, 'quantity')),
                ];
                $this->connection->table('log_conversion')->insertOrIgnore(
                    $this->available('log_conversion', [...$conversion, ...$request->visitProperties]),
                );
                $this->connection->table('log_visit')->where('idvisit', (int) $visitId)->update(
                    $this->available('log_visit', [
                        'visit_goal_converted' => 1,
                        'visit_goal_buyer' => $request->ecommerceOrderId === null ? 0 : 1,
                    ]),
                );
                if ($request->ecommerceOrderId !== null) {
                    $this->recordEcommerceItems($request, (int) $visitId, $visitor, $now);
                }
            }
        });
    }

    private function action(string $name, int $type): int
    {
        $hash = (int) sprintf('%u', crc32($name));
        $id = $this->connection->table('log_action')->where(['type' => $type, 'hash' => $hash, 'name' => $name])->value('idaction');

        return is_numeric($id) ? (int) $id : (int) $this->connection->table('log_action')->insertGetId(['name' => $name, 'hash' => $hash, 'type' => $type, 'url_prefix' => 0], 'idaction');
    }

    private function recordEcommerceItems(TrackingRequest $request, int $visitId, string $visitor, string $now): void
    {
        foreach ($request->ecommerceItems as $item) {
            $categories = array_pad($item['categories'], 5, '');
            $values = [
                'idsite' => $request->siteId, 'idvisitor' => $visitor, 'server_time' => $now,
                'idvisit' => $visitId, 'idorder' => $request->ecommerceOrderId,
                'idaction_sku' => $this->action($item['sku'], 5),
                'idaction_name' => $this->actionOrZero($item['name'], 6),
                'idaction_category' => $this->actionOrZero($categories[0], 7),
                'idaction_category2' => $this->actionOrZero($categories[1], 7),
                'idaction_category3' => $this->actionOrZero($categories[2], 7),
                'idaction_category4' => $this->actionOrZero($categories[3], 7),
                'idaction_category5' => $this->actionOrZero($categories[4], 7),
                'price' => $item['price'], 'quantity' => $item['quantity'], 'deleted' => 0,
            ];
            $this->connection->table('log_conversion_item')->insertOrIgnore(
                $this->available('log_conversion_item', $values),
            );
        }
    }

    private function actionOrZero(string $name, int $type): int
    {
        return $name === '' ? 0 : $this->action($name, $type);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function available(string $table, array $values): array
    {
        $columns = array_flip($this->connection->getSchemaBuilder()->getColumnListing($table));

        return array_intersect_key($values, $columns);
    }
}
