<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\ApiAuthentication;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Config\InstallationConfig;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Settings\PolicySettingRepository;
use Illuminate\Http\Request;
use Matomo\Network\IP;

final readonly class ConfiguredTrackingRequestPolicy implements TrackingRequestPolicy
{
    private const string DNT_OPTION = 'PrivacyManager.doNotTrackEnabled';

    private const string IP_ANONYMIZER_OPTION = 'PrivacyManager.ipAnonymizerEnabled';

    private const string IP_MASK_OPTION = 'PrivacyManager.ipAddressMaskLength';

    private const string REFERRER_OPTION = 'PrivacyManager.anonymizeReferrer';

    private const string ORDER_ID_OPTION = 'PrivacyManager.anonymizeOrderId';

    private const string COOKIELESS_OPTION = 'PrivacyManager.forceCookielessTracking';

    private const string ANONYMIZED_IP_ENRICHMENT_OPTION = 'PrivacyManager.useAnonymizedIpForVisitEnrichment';

    /** @var list<string> */
    private const array GOOGLE_BOT_IP_RANGES = [
        '216.239.32.0/19',
        '64.233.160.0/19',
        '66.249.80.0/20',
        '72.14.192.0/18',
        '209.85.128.0/17',
        '66.102.0.0/20',
        '74.125.0.0/16',
        '64.18.0.0/20',
        '207.126.144.0/20',
        '173.194.0.0/16',
    ];

    /** @var list<string> */
    private const array BOT_IP_RANGES = [
        ...self::GOOGLE_BOT_IP_RANGES,
        '64.4.0.0/18',
        '65.52.0.0/14',
        '157.54.0.0/15',
        '157.56.0.0/14',
        '157.60.0.0/16',
        '207.46.0.0/16',
        '207.68.128.0/18',
        '207.68.192.0/20',
        '131.253.26.0/20',
        '131.253.24.0/20',
        '72.30.198.0/20',
        '72.30.196.0/20',
        '98.137.207.0/20',
        '1.202.218.8',
    ];

    public function __construct(
        private InstallationConfig $configuration,
        private OptionRepository $options,
        private CompliancePolicyStateRepository $compliance,
        private PolicySettingRepository $settings,
        private ApiAccessAuthorizer $authorizer,
    ) {}

    public function records(Request $request): bool
    {
        $record = $request->input('rec');
        if (! $this->configuration->trackingEnabled()
            || ! in_array($record, [1, '1', true], true)) {
            return false;
        }

        return ! MatomoCookie::fromRequest(
            $request,
            $this->configuration->ignoreVisitsCookieName(),
        )->ignoresVisits();
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
            if ($this->matchesExcludedUserAgent($userAgent, $agent)) {
                return true;
            }
        }

        return false;
    }

    private function matchesExcludedUserAgent(string $userAgent, string $agent): bool
    {
        if ($agent !== '' && stripos($userAgent, $agent) !== false) {
            return true;
        }

        try {
            return $agent !== ''
                && @preg_match($agent, '') !== false
                && preg_match($agent, $userAgent) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isPrefetch(Request $request): bool
    {
        $purpose = strtolower((string) $request->header('X-Purpose', ''));
        if (in_array($purpose, ['preview', 'instant'], true)) {
            return true;
        }

        return strtolower((string) $request->header('X-Moz', '')) === 'prefetch';
    }

    public function isKnownBotIp(Request $request, string $ipAddress): bool
    {
        $ip = IP::fromStringIP($ipAddress);
        if ($this->isChromeDataSaver($request, $ip)) {
            return false;
        }

        return $ip->isInRanges(self::BOT_IP_RANGES);
    }

    public function storedIpAddress(int $siteId, string $ipAddress): string
    {
        if (! $this->booleanOption(self::IP_ANONYMIZER_OPTION, $siteId, true)) {
            return $ipAddress;
        }

        $maskLength = $this->integerOption(self::IP_MASK_OPTION, $siteId, 2);

        return IP::fromStringIP($ipAddress)->anonymize(max(0, min(4, $maskLength)))->toString();
    }

    public function storedOrderId(int $siteId, string $orderId): string
    {
        if (! $this->booleanOption(self::ORDER_ID_OPTION, $siteId, false)) {
            return $orderId;
        }

        return hash_hmac('sha1', random_bytes(16).$orderId, $this->configuration->salt());
    }

    public function collectsUserId(int $siteId): bool
    {
        return $this->settings->siteBoolean($siteId, 'UserId', 'user_id_disabled') !== true
            && ! $this->compliance->settingEnforced('UserId', 'UserIdDisabled', $siteId);
    }

    public function referrerAnonymisation(int $siteId): string
    {
        $mode = $this->option(self::REFERRER_OPTION, $siteId) ?? '';
        $modes = ['', 'exclude_query', 'exclude_path', 'exclude_all'];
        if (! in_array($mode, $modes, true)) {
            $mode = '';
        }

        if ($this->compliance->settingEnforced('PrivacyManager', 'ReferrerAnonymisation', $siteId)
            && in_array($mode, ['', 'exclude_query'], true)) {
            return 'exclude_path';
        }

        return $mode;
    }

    public function masksCampaignParameters(int $siteId): bool
    {
        return $this->settings->siteBoolean(
            $siteId,
            'PrivacyManager',
            'campaign_parameter_values_masked',
        ) === true || $this->compliance->settingEnforced(
            'PrivacyManager',
            'CampaignParameterValuesMasked',
            $siteId,
        );
    }

    public function collectsScreenResolution(int $siteId): bool
    {
        return ! $this->compliance->settingEnforced(
            'Resolution',
            'ScreenResolutionDetectionDisabled',
            $siteId,
        );
    }

    public function forcesCookielessTracking(int $siteId): bool
    {
        return $this->booleanOption(self::COOKIELESS_OPTION, $siteId, false);
    }

    public function allowsPrivilegedOverrides(Request $request, int $siteId): bool
    {
        if (! $this->configuration->trackingRequestsRequireAuthentication()) {
            return true;
        }

        $token = $this->trackingToken($request);
        if ($token === null) {
            return false;
        }

        $authentication = new ApiAuthentication(
            $token,
            $this->tokenIsSecure($request, $token),
            false,
            null,
        );

        return $this->authorizer->hasSuperUserAccess($authentication)
            || in_array(
                $siteId,
                $this->authorizer->siteIdsWithMinimumRole($authentication, SiteAccessRole::Write),
                true,
            );
    }

    private function trackingToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');
        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $bearer = substr($authorization, 7);

            return $bearer === '' ? null : $bearer;
        }

        $token = $request->input('token_auth');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function tokenIsSecure(Request $request, string $token): bool
    {
        $authorization = $request->headers->get('Authorization');
        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')
            && substr($authorization, 7) === $token) {
            return true;
        }

        $postToken = $request->request->get('token_auth');

        return is_string($postToken) && $postToken === $token;
    }

    public function usesAnonymizedIpForEnrichment(int $siteId): bool
    {
        return $this->booleanOption(self::ANONYMIZED_IP_ENRICHMENT_OPTION, $siteId, false);
    }

    private function isChromeDataSaver(Request $request, IP $ip): bool
    {
        $via = strtolower((string) $request->header('Via', ''));

        return $via !== ''
            && str_contains($via, 'chrome-compression-proxy')
            && $ip->isInRanges(self::GOOGLE_BOT_IP_RANGES);
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
