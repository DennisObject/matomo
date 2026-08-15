<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Goals\SiteTrackerCacheInvalidator;
use App\Matomo\Privacy\CompliancePolicyStateRepository;
use App\Matomo\Privacy\Events\CompliancePolicyStatusChanged;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class PrivacyManagerComplianceStatusApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private PasswordConfirmationVerifier $passwords,
        private CompliancePolicyStateRepository $policies,
        private SiteTrackerCacheInvalidator $siteCache,
        private TrackerCacheInvalidator $trackerCache,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->privacyComplianceStatus !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The privacy compliance status handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        $parameters = $request->privacyComplianceStatus
            ?? throw new LogicException('The privacy compliance status parameters were not parsed.');
        if ($parameters->policy !== 'cnil_v1') {
            return $this->responses->error($request, 'Invalid compliance type.', 400);
        }

        $idSite = $this->siteId($parameters->site);
        if ($idSite === false) {
            return $this->responses->error($request, 'The idSite must be a positive integer or all.', 400);
        }

        if ($request->authentication->sessionId !== null) {
            $login = $this->authorizer->authenticatedLogin($request->authentication);
            if ($login === null || $parameters->passwordConfirmation === null
                || ! $this->passwords->isCorrect($login, $parameters->passwordConfirmation)) {
                return $this->responses->error($request, 'The password confirmation is invalid.', 403);
            }
        }

        $this->policies->setActive($idSite, $parameters->enforce);
        if ($idSite !== null) {
            $this->siteCache->clear($idSite);
        }

        $this->trackerCache->clearGeneral();
        $this->events->dispatch(new CompliancePolicyStatusChanged(
            $parameters->enforce,
            $idSite,
            $parameters->policy,
        ));

        return $this->responses->scalar($request, $parameters->enforce);
    }

    private function siteId(string $site): int|null|false
    {
        if ($site === 'all') {
            return null;
        }

        if (preg_match('/^[1-9][0-9]*$/D', $site) !== 1) {
            return false;
        }

        return (int) $site;
    }
}
