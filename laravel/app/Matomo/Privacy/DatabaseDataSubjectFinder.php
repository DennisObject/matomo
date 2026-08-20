<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Archiving\VisitSegmentApplicator;
use App\Matomo\Geolocation\CountryMetadataProvider;
use App\Matomo\Reporting\DeviceDetectionMetadata;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\Connection;
use stdClass;

final readonly class DatabaseDataSubjectFinder implements DataSubjectFinder
{
    public function __construct(
        private Connection $connection,
        private VisitSegmentApplicator $segments,
        private SiteRepository $sites,
        private DeviceDetectionMetadata $devices,
        private CountryMetadataProvider $countries,
    ) {}

    public function find(array $siteIds, string $segment, string $language): array
    {
        $siteIds = array_values(array_filter(
            $siteIds,
            $this->profileEnabled(...),
        ));
        if ($siteIds === []) {
            return [];
        }

        $query = $this->connection->table('log_visit')
            ->whereIn('log_visit.idsite', $siteIds)
            ->orderByDesc('log_visit.visit_last_action_time')
            ->orderByDesc('log_visit.idvisit')
            ->limit(401);
        $this->segments->apply($query, $segment);

        return array_values(array_map(
            fn (stdClass $row): array => $this->format($row, $language),
            $query->get($this->columns())->all(),
        ));
    }

    private function profileEnabled(int $siteId): bool
    {
        $settingNames = ['disable_visitor_log', 'disable_visitor_profile'];
        if ($this->connection->getSchemaBuilder()->hasTable('plugin_setting')
            && $this->connection->table('plugin_setting')
                ->where('plugin_name', 'Live')
                ->where('user_login', '')
                ->whereIn('setting_name', $settingNames)
                ->whereIn('setting_value', ['1', 1, true, 'true'])
                ->exists()) {
            return false;
        }

        if (! $this->connection->getSchemaBuilder()->hasTable('site_setting')) {
            return true;
        }

        return ! $this->connection->table('site_setting')
            ->where('idsite', $siteId)
            ->where('plugin_name', 'Live')
            ->whereIn('setting_name', $settingNames)
            ->whereIn('setting_value', ['1', 1, true, 'true'])
            ->exists();
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'idvisit', 'idsite', 'visit_last_action_time', 'idvisitor', 'location_ip', 'user_id',
            'config_device_type', 'config_device_model', 'config_os', 'config_os_version',
            'config_browser_name', 'config_browser_version', 'location_country', 'location_region',
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function format(stdClass $row, string $language): array
    {
        $siteId = (int) $row->idsite;
        $countryCode = strtolower((string) ($row->location_country ?? ''));
        $regionCode = (string) ($row->location_region ?? '');
        $deviceType = (int) ($row->config_device_type ?? 0);
        $osCode = (string) ($row->config_os ?? '');
        $os = $osCode.';'.(string) ($row->config_os_version ?? '');
        $browserCode = (string) ($row->config_browser_name ?? '');
        $browser = $browserCode.';'.(string) ($row->config_browser_version ?? '');
        $details = $this->sites->details($siteId);

        return [
            'lastActionDateTime' => (string) $row->visit_last_action_time,
            'idVisit' => (int) $row->idvisit,
            'idSite' => $siteId,
            'siteName' => is_string($details['name'] ?? null) ? $details['name'] : '',
            'visitorId' => bin2hex((string) ($row->idvisitor ?? '')),
            'visitIp' => $this->ip((string) ($row->location_ip ?? '')),
            'userId' => $row->user_id === null ? null : (string) $row->user_id,
            'deviceType' => $this->devices->deviceTypeLabel($deviceType, $language),
            'deviceModel' => $this->devices->modelLabel((string) ($row->config_device_model ?? ''), $language),
            'deviceTypeIcon' => $this->devices->deviceTypeLogo($deviceType),
            'operatingSystem' => $this->devices->osLabel($os, $language),
            'operatingSystemIcon' => $this->devices->osLogo($osCode, $language),
            'browser' => $this->devices->browserVersionLabel($browser, $language),
            'browserFamilyDescription' => $this->devices->browserLabel($browserCode, $language),
            'browserIcon' => $this->devices->browserLogo($browserCode, $language),
            'country' => $this->countries->countryName($countryCode, $language),
            'region' => $this->countries->regionName($countryCode, $regionCode, $language),
            'countryFlag' => $this->countries->flag($countryCode),
        ];
    }

    private function ip(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $ip = @inet_ntop($binary);

        return is_string($ip) ? $ip : '';
    }
}
