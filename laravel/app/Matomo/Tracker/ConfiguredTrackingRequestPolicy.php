<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Config\InstallationConfig;
use App\Matomo\Options\OptionRepository;
use Illuminate\Http\Request;
use Matomo\Network\IP;

final readonly class ConfiguredTrackingRequestPolicy implements TrackingRequestPolicy
{
    private const string DNT_OPTION = 'PrivacyManager.doNotTrackEnabled';

    private const string IP_ANONYMIZER_OPTION = 'PrivacyManager.ipAnonymizerEnabled';

    private const string IP_MASK_OPTION = 'PrivacyManager.ipAddressMaskLength';

    public function __construct(
        private InstallationConfig $configuration,
        private OptionRepository $options,
    ) {}

    public function records(Request $request): bool
    {
        $record = $request->input('rec');
        if (! $this->configuration->trackingEnabled()
            || ! in_array($record, [1, '1', true], true)) {
            return false;
        }

        return $request->cookie($this->configuration->ignoreVisitsCookieName()) !== '*';
    }

    public function honorsDoNotTrack(Request $request): bool
    {
        $dnt = $request->header('X-Do-Not-Track') === '1'
            || str_starts_with((string) $request->header('DNT'), '1');

        return $dnt && $this->booleanOption(self::DNT_OPTION, $this->siteId($request), false);
    }

    public function excludesVisit(array $site, string $ipAddress, string $userAgent): bool
    {
        $ranges = $this->list($this->options->value('SitesManager_ExcludedIpsGlobal'));
        $ranges = [...$ranges, ...$this->list($site['excluded_ips'] ?? null)];
        if ($ranges !== [] && IP::fromStringIP($ipAddress)->isInRanges($ranges)) {
            return true;
        }

        $agents = $this->list($this->options->value('SitesManager_ExcludedUserAgentsGlobal'));
        $agents = [...$agents, ...$this->list($site['excluded_user_agents'] ?? null)];

        foreach ($agents as $agent) {
            if (stripos($userAgent, $agent) !== false) {
                return true;
            }
        }

        return false;
    }

    public function storedIpAddress(int $siteId, string $ipAddress): string
    {
        if (! $this->booleanOption(self::IP_ANONYMIZER_OPTION, $siteId, true)) {
            return $ipAddress;
        }

        $maskLength = $this->integerOption(self::IP_MASK_OPTION, $siteId, 2);

        return IP::fromStringIP($ipAddress)->anonymize(max(0, min(4, $maskLength)))->toString();
    }

    private function siteId(Request $request): ?int
    {
        $siteId = $request->input('idsite');

        return is_scalar($siteId) && (string) (int) $siteId === (string) $siteId && (int) $siteId > 0
            ? (int) $siteId
            : null;
    }

    private function booleanOption(string $name, ?int $siteId, bool $default): bool
    {
        $value = $this->option($name, $siteId);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true'], true);
    }

    private function integerOption(string $name, ?int $siteId, int $default): int
    {
        $value = $this->option($name, $siteId);

        return $value !== null && preg_match('/^-?[0-9]+$/D', $value) === 1 ? (int) $value : $default;
    }

    private function option(string $name, ?int $siteId): ?string
    {
        if ($siteId !== null) {
            $value = $this->options->value(str_replace('.', ".idSite({$siteId}).", $name));
            if ($value !== null) {
                return $value;
            }
        }

        return $this->options->value($name);
    }

    /** @return list<string> */
    private function list(int|string|null $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
