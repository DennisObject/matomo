<?php

declare(strict_types=1);

namespace App\Matomo\Live;

use App\Matomo\Archiving\VisitSegmentApplicator;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Reporting\DeviceDetectionMetadata;
use App\Matomo\Reporting\DurationFormatter;
use App\Matomo\Sites\CurrencyProvider;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use stdClass;

final readonly class DatabaseLiveVisitRepository implements LiveVisitRepository
{
    public function __construct(
        private Connection $connection,
        private VisitSegmentApplicator $segments,
        private SiteRepository $sites,
        private DurationFormatter $durations,
        private DeviceDetectionMetadata $devices,
        private CountryMetadataProvider $countries,
        private CurrencyProvider $currencies,
        private MatomoTranslator $translator,
    ) {}

    public function visits(
        array $siteIds,
        ?string $segment,
        ?string $start,
        ?string $end,
        ?int $minimumTimestamp,
        int $offset,
        int $limit,
        bool $ascending = false,
        ?string $visitorId = null,
        bool $fetchActions = true,
        bool $flat = false,
        ?string $intersectSegment = null,
        string $language = 'en',
    ): array {
        if ($siteIds === [] || $limit === 0) {
            return [];
        }

        $query = $this->connection->table('log_visit')->whereIn('log_visit.idsite', $siteIds);
        if ($start !== null) {
            $query->where('log_visit.visit_last_action_time', '>=', $start);
        }

        if ($end !== null) {
            $query->where('log_visit.visit_last_action_time', '<=', $end);
        }

        if ($minimumTimestamp !== null) {
            $query->where('log_visit.visit_last_action_time', '>=', date('Y-m-d H:i:s', $minimumTimestamp));
        }

        if ($visitorId !== null) {
            $binary = hex2bin($visitorId);
            if ($binary === false) {
                return [];
            }

            $query->where('log_visit.idvisitor', $binary);
        }

        if (! $this->segments->apply($query, $segment)) {
            throw new \InvalidArgumentException('The requested segment is not supported.');
        }

        if ($intersectSegment !== null && $intersectSegment !== '') {
            $intersected = $this->connection->table('log_visit')
                ->whereIn('log_visit.idsite', $siteIds)
                ->select('log_visit.idvisit');
            if (! $this->segments->apply($intersected, $intersectSegment)) {
                throw new \InvalidArgumentException('The requested intersect segment is not supported.');
            }

            $query->whereIn('log_visit.idvisit', $intersected);
        }

        $direction = $ascending ? 'asc' : 'desc';
        $rows = $query->orderBy('log_visit.visit_last_action_time', $direction)
            ->orderBy('log_visit.idvisit', $direction)
            ->offset(max(0, $offset))->limit(max(0, $limit))->get($this->columns())->all();

        $visits = array_values(array_map(
            fn (stdClass $row): array => $this->format($row, $language),
            $rows,
        ));
        if ($fetchActions) {
            $this->addActions($visits, $language);
        }

        if ($flat) {
            $visits = array_map($this->flatten(...), $visits);
        }

        return $visits;
    }

    /** @return array<string, mixed> */
    private function format(stdClass $row, string $language): array
    {
        $values = (array) $row;
        $siteId = (int) $row->idsite;
        $last = (string) $row->visit_last_action_time;
        $first = (string) ($values['visit_first_action_time'] ?? $last);
        $lastTimestamp = strtotime($last);
        $lastTimestamp = $lastTimestamp === false ? 0 : $lastTimestamp;

        $firstTimestamp = strtotime($first);
        $firstTimestamp = $firstTimestamp === false ? 0 : $firstTimestamp;

        $duration = (int) ($values['visit_total_time'] ?? 0);
        $returning = (int) ($values['visitor_returning'] ?? 0);
        $converted = (int) ($values['visit_goal_converted'] ?? 0);
        $ecommerceStatus = match ((int) ($values['visit_goal_buyer'] ?? 0)) {
            1 => 'ordered', 2 => 'abandonedCart', 3 => 'orderedThenAbandonedCart', default => 'none',
        };
        $localTimestamp = strtotime('2012-12-21 '.($values['visitor_localtime'] ?? '00:00:00'));
        $localTimestamp = $localTimestamp === false ? 0 : $localTimestamp;

        $details = $this->sites->details($siteId);
        $currency = is_string($details['currency'] ?? null) ? $details['currency'] : '';
        $countryCode = strtolower((string) ($values['location_country'] ?? ''));
        $continentCode = $this->countries->continentCode($countryCode);
        $regionCode = (string) ($values['location_region'] ?? '');
        $deviceType = (int) ($values['config_device_type'] ?? 0);
        $deviceBrand = (string) ($values['config_device_brand'] ?? '');
        $deviceModel = (string) ($values['config_device_model'] ?? '');
        $osCode = (string) ($values['config_os'] ?? '');
        $osVersion = (string) ($values['config_os_version'] ?? '');
        $browserCode = (string) ($values['config_browser_name'] ?? '');
        $browserVersion = (string) ($values['config_browser_version'] ?? '');
        $referrerType = match ((int) ($values['referer_type'] ?? 1)) {
            2 => 'search', 3 => 'website', 6 => 'campaign', 7 => 'social', 8 => 'ai', default => 'direct',
        };

        return [
            'idSite' => $siteId,
            'idVisit' => (int) $row->idvisit,
            'visitorId' => bin2hex((string) ($values['idvisitor'] ?? '')),
            'visitIp' => $this->ip((string) ($values['location_ip'] ?? '')),
            'fingerprint' => bin2hex((string) ($values['config_id'] ?? '')),
            'siteName' => is_string($details['name'] ?? null) ? $details['name'] : '',
            'siteCurrency' => $currency,
            'siteCurrencySymbol' => $this->currencies->symbols()[$currency] ?? '',
            'serverDate' => substr($last, 0, 10),
            'visitServerHour' => (int) date('G', $lastTimestamp),
            'lastActionTimestamp' => $lastTimestamp,
            'lastActionDateTime' => $last,
            'serverTimestamp' => $lastTimestamp,
            'firstActionTimestamp' => $firstTimestamp,
            'userId' => isset($values['user_id']) ? (string) $values['user_id'] : null,
            'visitorType' => $returning === 2 ? 'returningCustomer' : ($returning === 1 ? 'returning' : 'new'),
            'visitorTypeIcon' => $returning > 0 ? 'plugins/Live/images/returningVisitor.png' : null,
            'visitConverted' => $converted,
            'visitConvertedIcon' => $converted > 0 ? 'plugins/Morpheus/images/goal.svg' : null,
            'visitCount' => (int) ($values['visitor_count_visits'] ?? 1),
            'visitEcommerceStatus' => $ecommerceStatus,
            'visitEcommerceStatusIcon' => match ($ecommerceStatus) {
                'ordered', 'orderedThenAbandonedCart' => 'plugins/Morpheus/images/ecommerceOrder.svg',
                'abandonedCart' => 'plugins/Morpheus/images/ecommerceAbandonedCart.svg',
                default => null,
            },
            'daysSinceFirstVisit' => (int) floor(((int) ($values['visitor_seconds_since_first'] ?? 0)) / 86400),
            'secondsSinceFirstVisit' => (int) ($values['visitor_seconds_since_first'] ?? 0),
            'daysSinceLastEcommerceOrder' => (int) floor(((int) ($values['visitor_seconds_since_order'] ?? 0)) / 86400),
            'secondsSinceLastEcommerceOrder' => $values['visitor_seconds_since_order'] ?? null,
            'visitDuration' => $duration,
            'visitDurationPretty' => $this->durations->sentence($duration),
            'searches' => (int) ($values['visit_total_searches'] ?? 0),
            'actions' => (int) ($values['visit_total_actions'] ?? 0),
            'interactions' => (int) ($values['visit_total_interactions'] ?? 0),
            'events' => (int) ($values['visit_total_events'] ?? 0),
            'referrerName' => html_entity_decode((string) ($values['referer_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'referrerType' => $referrerType,
            'referrerKeyword' => urldecode((string) ($values['referer_keyword'] ?? '')),
            'referrerUrl' => (string) ($values['referer_url'] ?? ''),
            'languageCode' => (string) ($values['location_browser_lang'] ?? ''),
            'deviceType' => $this->devices->deviceTypeLabel($deviceType, $language),
            'deviceTypeIcon' => $this->devices->deviceTypeLogo($deviceType),
            'deviceBrand' => $this->devices->brandLabel($deviceBrand, $language),
            'deviceModel' => $this->devices->modelLabel($deviceModel, $language),
            'operatingSystem' => $this->devices->osLabel($osCode.';'.$osVersion, $language),
            'operatingSystemName' => $this->devices->osLabel($osCode, $language),
            'operatingSystemIcon' => $this->devices->osLogo($osCode, $language),
            'operatingSystemCode' => $osCode,
            'operatingSystemVersion' => $osVersion,
            'browserFamily' => (string) ($values['config_browser_engine'] ?? ''),
            'browserFamilyDescription' => $this->devices->browserEngineLabel((string) ($values['config_browser_engine'] ?? ''), $language),
            'browser' => $this->devices->browserVersionLabel($browserCode.';'.$browserVersion, $language),
            'browserName' => $this->devices->browserLabel($browserCode, $language),
            'browserIcon' => $this->devices->browserLogo($browserCode, $language),
            'browserCode' => $browserCode,
            'browserVersion' => $browserVersion,
            'resolution' => $values['config_resolution'] ?? null,
            'plugins' => $this->plugins($values),
            'pluginsIcons' => $this->pluginIcons($values),
            'daysSinceLastVisit' => (int) floor(((int) ($values['visitor_seconds_since_last'] ?? 0)) / 86400),
            'secondsSinceLastVisit' => (int) ($values['visitor_seconds_since_last'] ?? 0),
            'visitLocalTime' => (string) ($values['visitor_localtime'] ?? ''),
            'visitLocalHour' => (int) date('G', $localTimestamp),
            'continent' => $this->countries->continentName($continentCode, $language),
            'continentCode' => $continentCode,
            'country' => $this->countries->countryName($countryCode, $language),
            'countryCode' => $countryCode,
            'countryFlag' => $this->countries->flag($countryCode),
            'region' => $this->countries->regionName($countryCode, $regionCode, $language),
            'regionCode' => $regionCode,
            'city' => $values['location_city'] ?? null,
            'latitude' => $values['location_latitude'] ?? null,
            'longitude' => $values['location_longitude'] ?? null,
            'actionDetails' => [],
            'goalConversions' => 0,
        ];
    }

    private function ip(string $binary): string
    {
        $ip = $binary === '' ? false : @inet_ntop($binary);

        return is_string($ip) ? $ip : '';
    }

    /** @return list<string> */
    private function columns(): array
    {
        $wanted = [
            'idvisit', 'idsite', 'idvisitor', 'location_ip', 'config_id', 'user_id',
            'visit_first_action_time', 'visit_last_action_time', 'visit_total_time',
            'visit_total_actions', 'visit_total_interactions', 'visit_total_searches', 'visit_total_events',
            'visit_goal_converted', 'visit_goal_buyer', 'visitor_count_visits', 'visitor_returning',
            'visitor_seconds_since_first', 'visitor_seconds_since_last', 'visitor_seconds_since_order',
            'referer_type', 'referer_name', 'referer_keyword', 'referer_url', 'location_browser_lang',
            'config_device_type', 'config_device_brand', 'config_device_model', 'config_os',
            'config_os_version', 'config_browser_engine', 'config_browser_name', 'config_browser_version',
            'config_resolution', 'location_country', 'location_region', 'location_city',
            'location_latitude', 'location_longitude', 'visitor_localtime',
            'config_pdf', 'config_flash', 'config_java', 'config_director', 'config_quicktime',
            'config_realplayer', 'config_windowsmedia', 'config_gears', 'config_silverlight', 'config_cookie',
        ];
        $available = $this->connection->getSchemaBuilder()->getColumnListing('log_visit');

        return array_values(array_intersect($wanted, $available));
    }

    /** @param array<string, mixed> $values */
    private function plugins(array $values): string
    {
        return implode(', ', array_keys(array_filter([
            'pdf' => $values['config_pdf'] ?? 0,
            'flash' => $values['config_flash'] ?? 0,
            'java' => $values['config_java'] ?? 0,
            'director' => $values['config_director'] ?? 0,
            'quicktime' => $values['config_quicktime'] ?? 0,
            'realplayer' => $values['config_realplayer'] ?? 0,
            'windowsmedia' => $values['config_windowsmedia'] ?? 0,
            'gears' => $values['config_gears'] ?? 0,
            'silverlight' => $values['config_silverlight'] ?? 0,
            'cookie' => $values['config_cookie'] ?? 0,
        ], static fn (mixed $enabled): bool => (int) $enabled === 1)));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{pluginIcon: string, pluginName: string}>|null
     */
    private function pluginIcons(array $values): ?array
    {
        $plugins = $this->plugins($values);
        if ($plugins === '') {
            return null;
        }

        return array_map(
            static fn (string $plugin): array => [
                'pluginIcon' => 'plugins/Morpheus/icons/dist/plugins/'.$plugin.'.png',
                'pluginName' => $plugin,
            ],
            explode(', ', $plugins),
        );
    }

    /** @param list<array<string, mixed>> $visits */
    private function addActions(array &$visits, string $language): void
    {
        if ($visits === [] || ! $this->connection->getSchemaBuilder()->hasTable('log_link_visit_action')
            || ! $this->connection->getSchemaBuilder()->hasTable('log_action')) {
            return;
        }

        $visitIndexes = [];
        foreach ($visits as $index => $visit) {
            $visitIndexes[(int) $visit['idVisit']] = $index;
        }

        $actions = $this->connection->table('log_link_visit_action as link')
            ->leftJoin('log_action as url', 'link.idaction_url', '=', 'url.idaction')
            ->leftJoin('log_action as title', 'link.idaction_name', '=', 'title.idaction')
            ->leftJoin('log_action as event_category', 'link.idaction_event_category', '=', 'event_category.idaction')
            ->leftJoin('log_action as event_action', 'link.idaction_event_action', '=', 'event_action.idaction')
            ->leftJoin('log_action as event_name', 'link.idaction_name', '=', 'event_name.idaction')
            ->whereIn('link.idvisit', array_keys($visitIndexes))
            ->orderBy('link.idvisit')->orderBy('link.server_time')->orderBy('link.idlink_va')
            ->get([
                'link.idvisit', 'link.idlink_va', 'link.idpageview', 'link.server_time',
                'link.time_spent_ref_action', 'link.custom_float', 'link.search_cat', 'link.search_count',
                'url.idaction as pageIdAction', 'url.type', 'url.name as url', 'url.url_prefix',
                'title.name as pageTitle',
                'event_category.name as eventCategory', 'event_action.name as eventAction',
                'event_name.name as eventName',
            ]);
        foreach ($actions as $row) {
            $index = $visitIndexes[(int) $row->idvisit] ?? null;
            if ($index === null) {
                continue;
            }

            $event = $row->eventCategory !== null;
            $type = $event ? 10 : (int) ($row->type ?? 0);
            $action = [
                'type' => match ($type) {
                    2 => 'outlink',
                    3 => 'download',
                    8 => 'search',
                    10 => 'event',
                    default => 'action',
                },
                'url' => $this->actionUrl((string) ($row->url ?? ''), $row->url_prefix),
                'pageTitle' => (string) ($row->pageTitle ?? ''),
                'pageIdAction' => (int) ($row->pageIdAction ?? 0),
                'idpageview' => $row->idpageview,
                'pageId' => (int) $row->idlink_va,
                'serverTimePretty' => (string) $row->server_time,
                'timestamp' => strtotime((string) $row->server_time) ?: 0,
            ];
            if ($type === 8) {
                $action['siteSearchKeyword'] = $action['pageTitle'];
                $action['siteSearchCategory'] = $row->search_cat;
                $action['siteSearchCount'] = $row->search_count;
                unset($action['pageTitle']);
            }

            if ($event) {
                $action['eventCategory'] = (string) $row->eventCategory;
                $action['eventAction'] = (string) ($row->eventAction ?? '');
                $action['eventName'] = (string) ($row->eventName ?? '');
                if ($row->custom_float !== null) {
                    $action['eventValue'] = round((float) $row->custom_float, 3);
                }
            }

            if ($row->custom_float !== null) {
                $action['generationTimeMilliseconds'] = (float) $row->custom_float;
            }

            if ($row->time_spent_ref_action !== null) {
                $action['timeSpentRef'] = (int) $row->time_spent_ref_action;
            }

            $action += $this->actionPresentation($action, $language);
            $visits[$index]['actionDetails'][] = $action;
        }

        $this->addGoalConversions($visits, $visitIndexes, $language);
    }

    /**
     * @param  list<array<string, mixed>>  $visits
     * @param  array<int, int>  $visitIndexes
     */
    private function addGoalConversions(array &$visits, array $visitIndexes, string $language): void
    {
        $schema = $this->connection->getSchemaBuilder();
        if (! $schema->hasTable('log_conversion') || ! $schema->hasTable('goal')
            || ! $schema->hasColumns('log_conversion', ['idgoal', 'revenue', 'idlink_va', 'url'])) {
            return;
        }

        $rows = $this->connection->table('log_conversion as conversion')
            ->leftJoin('goal', static function ($join): void {
                $join->on('goal.idsite', '=', 'conversion.idsite')
                    ->on('goal.idgoal', '=', 'conversion.idgoal');
            })
            ->whereIn('conversion.idvisit', array_keys($visitIndexes))
            ->where('conversion.idgoal', '>', 0)
            ->where(static fn ($query) => $query->whereNull('goal.deleted')->orWhere('goal.deleted', 0))
            ->orderBy('conversion.idvisit')->orderBy('conversion.server_time')
            ->get([
                'conversion.idvisit', 'conversion.idgoal', 'conversion.revenue', 'conversion.idlink_va',
                'conversion.server_time', 'conversion.url', 'goal.name as goalName',
            ]);
        foreach ($rows as $row) {
            $index = $visitIndexes[(int) $row->idvisit] ?? null;
            if ($index === null || $row->goalName === null) {
                continue;
            }

            $visits[$index]['actionDetails'][] = [
                'type' => 'goal',
                'goalName' => html_entity_decode((string) $row->goalName, ENT_QUOTES, 'UTF-8'),
                'goalId' => (int) $row->idgoal,
                'revenue' => (float) $row->revenue,
                'goalPageId' => $row->idlink_va,
                'serverTimePretty' => (string) $row->server_time,
                'url' => (string) $row->url,
                'icon' => 'plugins/Morpheus/images/goal.png',
                'iconSVG' => 'plugins/Morpheus/images/goal.svg',
                'title' => $this->translator->translate('Goals_GoalConversion', $language),
                'subtitle' => html_entity_decode((string) $row->goalName, ENT_QUOTES, 'UTF-8'),
            ];
            $visits[$index]['goalConversions'] = (int) $visits[$index]['goalConversions'] + 1;
        }
    }

    private function actionUrl(string $url, mixed $prefix): string
    {
        if ($url === '' || str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $prefixes = [0 => 'http://', 1 => 'http://www.', 2 => 'https://', 3 => 'https://www.'];

        return ($prefixes[(int) $prefix] ?? '').$url;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function actionPresentation(array $action, string $language): array
    {
        $type = $action['type'] ?? 'action';
        $url = (string) ($action['url'] ?? '');
        $title = (string) ($action['pageTitle'] ?? '');

        return match ($type) {
            'download' => ['icon' => 'plugins/Morpheus/images/download.png', 'iconSVG' => 'plugins/Morpheus/images/download.svg', 'title' => $this->translator->translate('General_Download', $language), 'subtitle' => $url],
            'outlink' => ['icon' => 'plugins/Morpheus/images/link.png', 'iconSVG' => 'plugins/Morpheus/images/link.svg', 'title' => $this->translator->translate('General_Outlink', $language), 'subtitle' => $url],
            'search' => ['icon' => 'plugins/Morpheus/images/search.png', 'iconSVG' => 'plugins/Morpheus/images/search.svg', 'title' => $this->translator->translate('Actions_SubmenuSitesearch', $language), 'subtitle' => (string) ($action['siteSearchKeyword'] ?? '')],
            'event' => ['icon' => 'plugins/Morpheus/images/event.png', 'iconSVG' => 'plugins/Morpheus/images/event.svg', 'title' => $this->translator->translate('Events_Event', $language), 'subtitle' => (string) ($action['eventCategory'] ?? '')],
            default => ['icon' => '', 'iconSVG' => 'plugins/Morpheus/images/action.svg', 'title' => $title, 'subtitle' => $url, 'timeSpent' => 0, 'timeSpentPretty' => '0s'],
        };
    }

    /** @param array<string, mixed> $visit
     * @return array<string, mixed>
     */
    private function flatten(array $visit): array
    {
        $actions = is_array($visit['actionDetails'] ?? null) ? $visit['actionDetails'] : [];
        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                continue;
            }

            $number = $index + 1;
            $type = $action['type'] ?? null;
            $prefix = match ($type) {
                'outlink' => 'outlinkUrl',
                'download' => 'downloadUrl',
                'event' => 'eventUrl',
                'action' => 'pageUrl',
                default => null,
            };
            if ($prefix !== null && ! empty($action['url'])) {
                $visit[$prefix.'__'.$number] = $action['url'];
            }

            foreach (['pageTitle', 'siteSearchKeyword', 'eventCategory', 'eventAction', 'eventName', 'eventValue'] as $field) {
                if (! empty($action[$field])) {
                    $visit[$field.'__'.$number] = $action[$field];
                }
            }
        }

        return $visit;
    }
}
