<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Authentication\SiteAccessRole;
use App\Matomo\Sites\SiteRuntimeSettings;
use App\Matomo\Users\Events\UserAdded;
use App\Matomo\Users\Events\UserInvited;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\UserInvitationNotifier;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerCreateApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableUserRepository $users,
        private SiteRuntimeSettings $runtime,
        private PasswordConfirmationVerifier $passwords,
        private UserInvitationNotifier $invitations,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerCreate !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager create handler does not support this request.');
        }

        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error($request, 'You must have admin access to at least one website.', 401);
        }

        if (! $this->runtime->administrationEnabled()) {
            return $this->responses->error($request, 'Users administration is disabled.', 403);
        }

        $parameters = $request->usersManagerCreate
            ?? throw new LogicException('The UsersManager create parameters were not parsed.');
        $creator = $this->authorizer->authenticatedLogin($request->authentication);
        if ($creator === null) {
            return $this->responses->error($request, 'Authentication is required.', 401);
        }

        if ($request->authentication->sessionId !== null
            && ($parameters->passwordConfirmation === null
                || ! $this->passwords->isCorrect($creator, $parameters->passwordConfirmation))) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        $validation = $this->validate($request);
        if ($validation !== null) {
            return $validation;
        }

        $siteId = $parameters->initialSiteId;
        if ($request->method === 'UsersManager.inviteUser' && $siteId === null) {
            return $this->responses->error($request, 'An initial website is required.', 400);
        }

        if (! $this->authorizer->hasSuperUserAccess($request->authentication) && $siteId === null) {
            return $this->responses->error($request, 'An initial website is required.', 400);
        }

        if ($siteId !== null && ! in_array(
            $siteId,
            $this->authorizer->siteIdsWithRole($request->authentication, SiteAccessRole::Admin),
            true,
        )) {
            return $this->responses->error($request, 'You do not have admin access to the initial website.', 401);
        }

        if ($request->method === 'UsersManager.addUser') {
            $result = $this->users->create(
                $parameters->login,
                $parameters->password ?? '',
                $parameters->email,
                $parameters->passwordIsHashed,
                $siteId,
            );
            if ($result !== 'created') {
                return $this->conflict($request, $result);
            }

            $this->events->dispatch(new UserAdded($parameters->login, $parameters->email, $creator));

            return $this->responses->success($request);
        }

        $expiryDays = $parameters->expiryDays ?? 7;
        if ($expiryDays < 1 || $expiryDays > 3650) {
            return $this->responses->error($request, 'Invitation expiry must be between 1 and 3650 days.', 400);
        }

        $result = $this->users->invite(
            $parameters->login,
            $parameters->email,
            $siteId ?? throw new LogicException('The invitation site was not validated.'),
            $expiryDays,
            $creator,
        );
        if ($result['result'] !== 'created') {
            return $this->conflict($request, $result['result']);
        }

        $token = $result['token'] ?? throw new LogicException('The invitation token was not returned.');
        $this->invitations->notify($parameters->login, $parameters->email, $token, $expiryDays);
        $this->events->dispatch(new UserInvited($parameters->login, $parameters->email));

        return $this->responses->success($request);
    }

    private function validate(ApiRequest $request): ?Response
    {
        $parameters = $request->usersManagerCreate
            ?? throw new LogicException('The UsersManager create parameters were not parsed.');
        if (preg_match('/^[A-Za-zÄäÖöÜüß0-9_.@+-]{2,100}$/Du', $parameters->login) !== 1) {
            return $this->responses->error($request, 'The user login format is invalid.', 400);
        }

        if (filter_var($parameters->email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->responses->error($request, 'The email address is invalid.', 400);
        }

        if ($request->method === 'UsersManager.addUser') {
            $password = $parameters->password ?? '';
            if ($parameters->passwordIsHashed) {
                if (strlen($password) !== 32 || ! ctype_xdigit($password)) {
                    return $this->responses->error($request, 'The password hash is invalid.', 400);
                }
            } elseif (strlen($password) < 6 || mb_strlen($password) > 200) {
                return $this->responses->error($request, 'The password must contain between 6 and 200 characters.', 400);
            }
        }

        return null;
    }

    private function conflict(ApiRequest $request, string $result): Response
    {
        return $this->responses->error($request, match ($result) {
            'login-exists' => 'The user login already exists.',
            'email-exists' => 'The email address already exists.',
            'login-is-email' => 'The user login is already used as an email address.',
            'email-is-login' => 'The email address is already used as a user login.',
            default => 'The user could not be created.',
        }, 409);
    }
}
