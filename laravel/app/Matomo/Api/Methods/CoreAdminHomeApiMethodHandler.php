<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Archiving\ArchiveInvalidationManager;
use App\Matomo\Archiving\ReportArchiver;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\CoreAdmin\BrandingManager;
use App\Matomo\CoreAdmin\CoreAdminSettings;
use App\Matomo\CoreAdmin\OptOutEmbedCodeGenerator;
use App\Matomo\Localization\LanguageResolver;
use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Localization\MutableLanguagePreferenceRepository;
use App\Matomo\Scheduling\ScheduledTaskRunner;
use App\Matomo\TrackingFailures\TrackingFailurePresenter;
use App\Matomo\TrackingFailures\TrackingFailureRepository;
use App\Matomo\UserChanges\UserChangeReadRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use LogicException;

final readonly class CoreAdminHomeApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private TrackingFailureRepository $failures,
        private TrackingFailurePresenter $presenter,
        private LanguageResolver $languages,
        private MutableLanguagePreferenceRepository $preferences,
        private UserChangeReadRepository $userChanges,
        private CoreAdminSettings $settings,
        private MatomoTranslator $translator,
        private BrandingManager $branding,
        private OptOutEmbedCodeGenerator $optOutEmbedCodes,
        private ArchiveInvalidationManager $archiveInvalidations,
        private ReportArchiver $reportArchiver,
        private ScheduledTaskRunner $scheduledTasks,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->isCoreAdminHomeRequest();
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The CoreAdminHome API handler does not support this request.');
        }

        if ($request->method === 'CoreAdminHome.whatIsNewMarkAllChangesReadForCurrentUser') {
            return $this->markChangesRead($request);
        }

        if (in_array($request->method, [
            'CoreAdminHome.setArchiveSettings',
            'CoreAdminHome.setTrustedHosts',
        ], true)) {
            return $this->updateSettings($request, $httpRequest);
        }

        if ($request->method === 'CoreAdminHome.setBrandingSettings') {
            return $this->updateBranding($request);
        }

        if (in_array($request->method, [
            'CoreAdminHome.getOptOutJSEmbedCode',
            'CoreAdminHome.getOptOutSelfContainedEmbedCode',
        ], true)) {
            return $this->optOutEmbedCode($request, $httpRequest);
        }

        if ($request->method === 'CoreAdminHome.invalidateArchivedReports') {
            return $this->invalidateArchives($request);
        }

        if ($request->method === 'CoreAdminHome.archiveReports') {
            return $this->archiveReports($request);
        }

        if ($request->method === 'CoreAdminHome.runScheduledTasks') {
            return $this->runScheduledTasks($request);
        }

        if ($request->method === 'CoreAdminHome.deleteTrackingFailure') {
            return $this->deleteOne($request);
        }

        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);

        if (! $superuser && ! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires admin access for at least one website.",
                401,
            );
        }

        $siteIds = $superuser
            ? []
            : $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin);

        if ($request->method === 'CoreAdminHome.deleteAllTrackingFailures') {
            if ($superuser) {
                $this->failures->deleteAll();
            } else {
                $this->failures->deleteForSites($siteIds);
            }

            return $this->responses->success($request);
        }

        $failures = $superuser ? $this->failures->all() : $this->failures->forSites($siteIds);
        $login = $this->authorizer->authenticatedLogin($request->authentication);

        return $this->responses->rows(
            $request,
            $this->presenter->present(
                $failures,
                $this->languages->resolve($httpRequest, $request->authentication),
                is_string($login) && $this->preferences->uses12HourClock($login),
            ),
        );
    }

    private function deleteOne(ApiRequest $request): Response
    {
        $parameters = $request->coreAdminHome
            ?? throw new LogicException('The CoreAdminHome API parameters were not parsed.');
        $siteId = $parameters->siteId
            ?? throw new LogicException('The CoreAdminHome site ID was not parsed.');
        $failureId = $parameters->failureId
            ?? throw new LogicException('The CoreAdminHome failure ID was not parsed.');
        $allowed = $this->authorizer->hasSuperUserAccess($request->authentication)
            || in_array(
                $siteId,
                $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin),
                true,
            );

        if (! $allowed) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires 'admin' access for the website id = {$siteId}.",
                401,
            );
        }

        $this->failures->delete($siteId, $failureId);

        return $this->responses->success($request);
    }

    private function markChangesRead(ApiRequest $request): Response
    {
        if (! $this->authorizer->hasSomeViewAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                'You must have view access to at least one website.',
                401,
            );
        }

        $login = $this->authorizer->authenticatedLogin($request->authentication);

        if ($login === null || $login === '' || strtolower($login) === 'anonymous') {
            return $this->responses->error(
                $request,
                'You must be logged in to access this functionality.',
                401,
            );
        }

        return $this->responses->scalar($request, $this->userChanges->markAllRead($login));
    }

    private function updateSettings(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        if (! $this->settings->generalSettingsAdminEnabled()) {
            return $this->responses->error($request, 'General settings admin is not enabled', 400);
        }

        $parameters = $request->coreAdminHome
            ?? throw new LogicException('The CoreAdminHome API parameters were not parsed.');

        if ($request->method === 'CoreAdminHome.setTrustedHosts') {
            $this->settings->replaceTrustedHosts($parameters->trustedHosts);

            return $this->responses->scalar($request, true);
        }

        $timeToLive = $parameters->todayArchiveTimeToLive
            ?? throw new LogicException('The archive time to live was not parsed.');

        if ($timeToLive <= 0) {
            return $this->responses->error(
                $request,
                $this->translator->translate(
                    'General_ExceptionInvalidArchiveTimeToLive',
                    $this->languages->resolve($httpRequest, $request->authentication),
                ),
                400,
            );
        }

        $this->settings->configureArchiving(
            $parameters->browserTriggerArchivingEnabled ?? false,
            $timeToLive,
        );

        return $this->responses->scalar($request, true);
    }

    private function updateBranding(ApiRequest $request): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $parameters = $request->coreAdminHome
            ?? throw new LogicException('The CoreAdminHome API parameters were not parsed.');

        return $this->responses->row(
            $request,
            $this->branding->update(
                $this->authorizer->authenticatedLogin($request->authentication) ?? '',
                $parameters->useCustomLogo ?? false,
                $parameters->hasCustomLogo ?? false,
                $parameters->hasCustomFavicon ?? false,
            ),
        );
    }

    private function optOutEmbedCode(ApiRequest $request, Request $httpRequest): Response
    {
        $options = $request->coreAdminHome->optOutEmbed
            ?? throw new LogicException('The CoreAdminHome opt-out parameters were not parsed.');

        try {
            $code = $request->method === 'CoreAdminHome.getOptOutJSEmbedCode'
                ? $this->optOutEmbedCodes->javascript($options)
                : $this->optOutEmbedCodes->selfContained(
                    $options,
                    $this->languages->resolve($httpRequest, $request->authentication),
                );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->scalar($request, $code);
    }

    private function invalidateArchives(ApiRequest $request): Response
    {
        $parameters = $request->coreAdminHome->archiveInvalidation
            ?? throw new LogicException('The CoreAdminHome archive invalidation parameters were not parsed.');
        $siteIds = $parameters->allSites
            ? $this->authorizer->siteIdsWithAtLeastViewAccess($request->authentication)
            : $parameters->siteIds;

        if ($siteIds === []) {
            return $this->responses->error(
                $request,
                "Specify a value for &idSites= as a comma separated list of website IDs, for which your token_auth has 'admin' permission",
                400,
            );
        }

        $adminSiteIds = $this->authorizer->siteIdsWithRole(
            $request->authentication,
            SiteAccessRole::Admin,
        );

        foreach ($siteIds as $siteId) {
            if (! in_array($siteId, $adminSiteIds, true)) {
                return $this->responses->error(
                    $request,
                    "You can't access this resource as it requires 'admin' access for the website id = {$siteId}.",
                    401,
                );
            }
        }

        try {
            $logs = $this->archiveInvalidations->invalidate(
                $siteIds,
                $parameters->dates,
                $parameters->period,
                $parameters->segment,
                $parameters->cascadeDown,
                $parameters->forceInvalidateNonexistent,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->values($request, $logs);
    }

    private function runScheduledTasks(ApiRequest $request): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        return $this->responses->rows($request, $this->scheduledTasks->run());
    }

    private function archiveReports(ApiRequest $request): Response
    {
        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error(
                $request,
                "You can't access this resource as it requires a 'superuser' access.",
                401,
            );
        }

        $parameters = $request->coreAdminHome->archiveReport
            ?? throw new LogicException('The CoreAdminHome archive report parameters were not parsed.');

        try {
            $result = $this->reportArchiver->archive($parameters);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->responses->error($request, $invalidArgumentException->getMessage(), 400);
        }

        return $this->responses->structured($request, [
            'idarchives' => $result->archiveIds,
            'nb_visits' => $result->visits,
        ]);
    }
}
