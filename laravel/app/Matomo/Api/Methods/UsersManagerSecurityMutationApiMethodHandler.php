<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Users\MutableUserRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerSecurityMutationApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableUserRepository $users,
        private SiteRuntimeSettings $runtime,
        private PasswordConfirmationVerifier $passwords,
        private TrackerCacheInvalidator $trackerCache,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerSecurityMutation !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager security mutation handler does not support this request.');
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication)) {
            return $this->responses->error($request, 'Superuser access is required.', 401);
        }

        $parameters = $request->usersManagerSecurityMutation
            ?? throw new LogicException('The UsersManager security mutation parameters were not parsed.');
        if (strcasecmp($parameters->login, 'anonymous') === 0) {
            return $this->responses->error($request, 'The anonymous user cannot be changed.', 400);
        }

        $requester = $this->authorizer->authenticatedLogin($request->authentication);
        if ($requester === null) {
            return $this->responses->error($request, 'Authentication is required.', 401);
        }

        $confirmationRequired = $request->method === 'UsersManager.setSuperUserAccess'
            || $request->authentication->sessionId !== null;
        if ($confirmationRequired && ($parameters->passwordConfirmation === null
            || ! $this->passwords->isCorrect($requester, $parameters->passwordConfirmation))) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        if ($request->method === 'UsersManager.logoutUser') {
            if (! $this->users->deleteSessions($parameters->login)) {
                return $this->responses->error($request, 'User does not exist: '.$parameters->login, 404);
            }

            return $this->responses->success($request);
        }

        if (! $this->runtime->administrationEnabled()) {
            return $this->responses->error($request, 'Users administration is disabled.', 403);
        }

        $result = $this->users->setSuperuser(
            $parameters->login,
            $parameters->superuserEnabled
                ?? throw new LogicException('The superuser access value was not parsed.'),
        );
        if ($result === 'not-found') {
            return $this->responses->error($request, 'User does not exist: '.$parameters->login, 404);
        }

        if ($result === 'only-superuser') {
            return $this->responses->error($request, 'The only superuser cannot lose superuser access.', 400);
        }

        $this->trackerCache->clearGeneral();

        return $this->responses->success($request);
    }
}
