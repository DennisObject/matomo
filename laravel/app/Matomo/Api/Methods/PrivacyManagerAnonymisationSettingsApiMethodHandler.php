<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Plugins\TrackerFileAvailability;
use App\Matomo\Privacy\AnonymisationSettingsRepository;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Privacy\Events\CustomTrackerUpdateRequested;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerAnonymisationSettingsApiMethodHandler implements ApiMethodHandler
{
    /** @var array<string, array{plugin: string, setting: string, requiredValue: bool|int|string}> */
    private const array POLICY_SETTINGS = [
        'ipAnonymizerEnabled' => ['plugin' => 'PrivacyManager', 'setting' => 'IPAnonymisation', 'requiredValue' => 1],
        'ipAddressMaskLength' => ['plugin' => 'PrivacyManager', 'setting' => 'IpAddressMaskLength', 'requiredValue' => 2],
        'anonymizeOrderId' => ['plugin' => 'Ecommerce', 'setting' => 'OrderIdAnonymization', 'requiredValue' => true],
        'anonymizeReferrer' => ['plugin' => 'PrivacyManager', 'setting' => 'ReferrerAnonymisation', 'requiredValue' => 'exclude_path'],
    ];

    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private AnonymisationSettingsRepository $settings,
        private CompliancePolicyStateRepository $policies,
        private PasswordConfirmationVerifier $passwords,
        private TrackerFileAvailability $trackerFile,
        private TrackerCacheInvalidator $trackerCache,
        private SiteTrackerCacheInvalidator $siteCache,
        private Dispatcher $events,
        private LanguageResolver $languages,
        private MatomoTranslator $translator,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyAnonymisationSettings !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy anonymisation settings handler does not support this request.');
        }

        $parameters = $request->privacyAnonymisationSettings
            ?? throw new LogicException('The privacy anonymisation settings parameters were not parsed.');
        if (! $this->authorized($request, $parameters->idSite)) {
            return $this->responses->error($request, 'Admin access is required.', 401);
        }

        if (! $parameters->mutation) {
            return $this->responses->structured($request, $this->read($request, $httpRequest, $parameters->idSite));
        }

        $idSite = $parameters->idSite;
        if ($idSite !== null && ! $parameters->useSiteSpecificSettings) {
            $this->settings->removeSiteSettings($idSite);
            $this->clearCaches($idSite);

            return $this->responses->scalar($request, true);
        }

        if ($parameters->randomizeConfigId) {
            $login = $this->authorizer->authenticatedLogin($request->authentication);
            if ($login === null || $parameters->passwordConfirmation === null
                || ! $this->passwords->isCorrect($login, $parameters->passwordConfirmation)) {
                return $this->responses->error($request, 'The password confirmation is invalid.', 403);
            }
        }

        $referrer = in_array($parameters->anonymizeReferrer, [
            '',
            'exclude_query',
            'exclude_path',
            'exclude_all',
        ], true) ? $parameters->anonymizeReferrer : '';
        $values = [
            'ipAnonymizerEnabled' => $parameters->ipEnabled
                ?? throw new LogicException('The IP anonymisation setting was not parsed.'),
            'ipAddressMaskLength' => $parameters->maskLength
                ?? throw new LogicException('The IP mask length was not parsed.'),
            'useAnonymizedIpForVisitEnrichment' => $parameters->useAnonymizedIpForEnrichment
                ?? throw new LogicException('The IP enrichment setting was not parsed.'),
            'anonymizeUserId' => $parameters->anonymizeUserId,
            'anonymizeOrderId' => $parameters->anonymizeOrderId,
            'anonymizeReferrer' => $referrer,
            'randomizeConfigId' => $parameters->randomizeConfigId,
        ];
        if ($idSite === null) {
            $values['forceCookielessTracking'] = $parameters->forceCookielessTracking;
        }

        $this->settings->replace($idSite, $values);
        $this->clearCaches($idSite);
        if ($idSite === null) {
            $this->events->dispatch(new CustomTrackerUpdateRequested);
        }

        return $this->responses->scalar($request, true);
    }

    private function authorized(ApiRequest $request, ?int $idSite): bool
    {
        if ($idSite === null) {
            return $this->authorizer->hasSuperUserAccess($request->authentication);
        }

        return in_array(
            $idSite,
            $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin),
            true,
        );
    }

    /** @return array<string, mixed> */
    private function read(ApiRequest $request, Request $httpRequest, ?int $idSite): array
    {
        $language = $this->languages->resolve($httpRequest, $request->authentication);
        $values = $this->settings->values($idSite);
        $values['useSiteSpecificSettings'] = $idSite !== null && $this->settings->usesSiteSettings($idSite);
        $values['maskLengthOptions'] = [
            ['key' => '1', 'value' => $this->translate('PrivacyManager_AnonymizeIpMaskLength', $language, [1, '192.168.100.xxx']), 'description' => ''],
            ['key' => '2', 'value' => $this->translate('PrivacyManager_AnonymizeIpMaskLength', $language, [2, '192.168.xxx.xxx']), 'description' => $this->translate('General_Recommended', $language)],
            ['key' => '3', 'value' => $this->translate('PrivacyManager_AnonymizeIpMaskLength', $language, [3, '192.xxx.xxx.xxx']), 'description' => ''],
            ['key' => '4', 'value' => $this->translate('PrivacyManager_AnonymizeIpMaskFully', $language), 'description' => ''],
        ];
        $values['useAnonymizedIpForVisitEnrichmentOptions'] = [
            ['key' => '1', 'value' => $this->translate('General_Yes', $language), 'description' => $this->translate('PrivacyManager_UseAnonymizedIpForVisitEnrichmentYesDesc', $language)],
            ['key' => '0', 'value' => $this->translate('General_No', $language), 'description' => $this->translate('PrivacyManager_UseAnonymizedIpForVisitEnrichmentNoDesc', $language)],
        ];
        $values['referrerAnonymizationOptions'] = [
            '' => $this->translate('PrivacyManager_AnonymizeReferrerExcludeNone', $language),
            'exclude_query' => $this->translate('PrivacyManager_AnonymizeReferrerExcludeQuery', $language),
            'exclude_path' => $this->translate('PrivacyManager_AnonymizeReferrerExcludePath', $language),
            'exclude_all' => $this->translate('PrivacyManager_AnonymizeReferrerExcludeAll', $language),
        ];
        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);
        $values['trackerFileName'] = $superuser ? 'matomo.js' : '';
        $values['trackerWritable'] = $superuser && $this->trackerFile->canUpdate();

        $metadata = [];
        foreach (self::POLICY_SETTINGS as $name => $policy) {
            if (! $this->policies->settingEnforced($policy['plugin'], $policy['setting'], $idSite)) {
                continue;
            }

            $metadata[$name] = [
                'compliancePolicyControlled' => [
                    'cnil_v1' => ['requiredValue' => $policy['requiredValue']],
                ],
                'idSite' => $idSite,
            ];
        }

        if ($metadata !== []) {
            $values['extraMetadata'] = $metadata;
        }

        return $values;
    }

    /** @param list<bool|int|string> $arguments */
    private function translate(string $key, string $language, array $arguments = []): string
    {
        return $this->translator->translate($key, $language, $arguments);
    }

    private function clearCaches(?int $idSite): void
    {
        if ($idSite !== null) {
            $this->siteCache->clear($idSite);
        }

        $this->trackerCache->clearGeneral();
    }
}
