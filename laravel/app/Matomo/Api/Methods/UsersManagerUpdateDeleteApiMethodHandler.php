<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Geolocation\TrackerCacheInvalidator;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Users\Events\UserDeleted;
use App\Matomo\Users\Events\UserUpdated;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\UserInvitationNotifier;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerUpdateDeleteApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableUserRepository $users,
        private SiteRuntimeSettings $runtime,
        private PasswordConfirmationVerifier $passwords,
        private UserInvitationNotifier $invitations,
        private TrackerCacheInvalidator $trackerCache,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerUpdateDelete !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager update/delete handler does not support this request.');
        }

        if (! $this->runtime->administrationEnabled()) {
            return $this->responses->error($request, 'Users administration is disabled.', 403);
        }

        $parameters = $request->usersManagerUpdateDelete
            ?? throw new LogicException('The UsersManager update/delete parameters were not parsed.');
        if (strcasecmp($parameters->login, 'anonymous') === 0) {
            return $this->responses->error($request, 'The anonymous user cannot be changed.', 400);
        }

        $requester = $this->authorizer->authenticatedLogin($request->authentication);
        if ($requester === null) {
            return $this->responses->error($request, 'Authentication is required.', 401);
        }

        return $request->method === 'UsersManager.updateUser'
            ? $this->update($request, $requester)
            : $this->delete($request, $requester);
    }

    private function update(ApiRequest $request, string $requester): Response
    {
        $parameters = $request->usersManagerUpdateDelete
            ?? throw new LogicException('The UsersManager update parameters were not parsed.');
        $superuser = $this->authorizer->hasSuperUserAccess($request->authentication);
        if (! $superuser && strcasecmp($requester, $parameters->login) !== 0) {
            return $this->responses->error($request, 'You can only update your own user account.', 401);
        }

        if ($parameters->email !== null && filter_var($parameters->email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->responses->error($request, 'The email address is invalid.', 400);
        }

        if ($parameters->password !== null) {
            $password = $parameters->password;
            $alreadyHashed = ($parameters->passwordIsHashed && (password_get_info($password)['algo'] ?? null) !== null);
            if ($parameters->passwordIsHashed && ! $alreadyHashed
                && (strlen($password) !== 32 || ! ctype_xdigit($password))) {
                return $this->responses->error($request, 'The password hash is invalid.', 400);
            }

            if (! $parameters->passwordIsHashed && (strlen($password) < 6 || mb_strlen($password) > 200)) {
                return $this->responses->error($request, 'The password must contain between 6 and 200 characters.', 400);
            }
        }

        $sensitive = $parameters->password !== null || $parameters->email !== null;
        if ($sensitive && ($parameters->passwordConfirmation === null
            || ! $this->passwords->isCorrect($requester, $parameters->passwordConfirmation))) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        $result = $this->users->update(
            $parameters->login,
            $parameters->password,
            $parameters->email,
            $parameters->passwordIsHashed,
            7,
        );
        if ($result['result'] === 'not-found') {
            return $this->responses->error($request, 'User does not exist: '.$parameters->login, 404);
        }

        if ($result['result'] === 'email-exists' || $result['result'] === 'email-is-login') {
            return $this->responses->error($request, 'The email address is already in use.', 409);
        }

        $email = $result['email'] ?? throw new LogicException('The updated email was not returned.');
        if (isset($result['inviteToken'])) {
            $this->invitations->notify($parameters->login, $email, $result['inviteToken'], 7);
        }

        $this->trackerCache->clearGeneral();
        $this->events->dispatch(new UserUpdated(
            $parameters->login,
            $result['passwordChanged'] ?? false,
            $email,
        ));

        return $this->responses->success($request);
    }

    private function delete(ApiRequest $request, string $requester): Response
    {
        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error($request, 'You must have admin access to at least one website.', 401);
        }

        $parameters = $request->usersManagerUpdateDelete
            ?? throw new LogicException('The UsersManager delete parameters were not parsed.');
        if ($request->authentication->sessionId !== null
            && ($parameters->passwordConfirmation === null
                || ! $this->passwords->isCorrect($requester, $parameters->passwordConfirmation))) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        $result = $this->users->delete(
            $parameters->login,
            $requester,
            $this->authorizer->hasSuperUserAccess($request->authentication),
        );
        if ($result === 'not-found' || $result === 'denied') {
            return $this->responses->error($request, 'User does not exist: '.$parameters->login, 404);
        }

        if ($result === 'only-superuser') {
            return $this->responses->error($request, 'The only superuser cannot be deleted.', 400);
        }

        $this->events->dispatch(new UserDeleted($parameters->login));
        $this->trackerCache->clearGeneral();

        return $this->responses->success($request);
    }
}
