<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use App\Matomo\Api\ApiResponseFactory;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\PasswordConfirmationVerifier;
use App\Matomo\Users\Events\UserInvitationLinkGenerated;
use App\Matomo\Users\Events\UserInvitationResent;
use App\Matomo\Users\MutableUserRepository;
use App\Matomo\Users\UserInvitationLinkFactory;
use App\Matomo\Users\UserInvitationNotifier;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;

final readonly class UsersManagerInviteMaintenanceApiMethodHandler implements ApiMethodHandler
{
    public function __construct(
        private ApiAccessAuthorizer $authorizer,
        private ApiResponseFactory $responses,
        private MutableUserRepository $users,
        private PasswordConfirmationVerifier $passwords,
        private UserInvitationNotifier $notifier,
        private UserInvitationLinkFactory $links,
        private Dispatcher $events,
    ) {}

    public function supports(ApiRequest $request): bool
    {
        return $request->usersManagerInviteMaintenance !== null;
    }

    public function handle(ApiRequest $request, Request $httpRequest): Response
    {
        if (! $this->supports($request)) {
            throw new LogicException('The UsersManager invitation handler does not support this request.');
        }

        if (! $this->authorizer->hasSomeAdminAccess($request->authentication)) {
            return $this->responses->error($request, 'You must have admin access to at least one website.', 401);
        }

        $parameters = $request->usersManagerInviteMaintenance
            ?? throw new LogicException('The UsersManager invitation parameters were not parsed.');
        $requester = $this->authorizer->authenticatedLogin($request->authentication);
        if ($requester === null) {
            return $this->responses->error($request, 'Authentication is required.', 401);
        }

        if ($request->authentication->sessionId !== null
            && ($parameters->passwordConfirmation === null
                || ! $this->passwords->isCorrect($requester, $parameters->passwordConfirmation))) {
            return $this->responses->error($request, 'The password confirmation is invalid.', 403);
        }

        if ($parameters->expiryDays < 1 || $parameters->expiryDays > 3650) {
            return $this->responses->error($request, 'Invitation expiry must be between 1 and 3650 days.', 400);
        }

        $linkOnly = $request->method === 'UsersManager.generateInviteLink';
        $result = $this->users->renewInvitation(
            $parameters->login,
            $parameters->expiryDays,
            $linkOnly,
            $requester,
            $this->authorizer->hasSuperUserAccess($request->authentication),
        );
        if ($result['result'] === 'not-pending') {
            return $this->responses->error($request, 'Pending user does not exist: '.$parameters->login, 404);
        }

        if ($result['result'] === 'denied') {
            return $this->responses->error($request, 'You cannot manage an invitation created by another user.', 401);
        }

        $email = $result['email'] ?? throw new LogicException('The invitation email was not returned.');
        $token = $result['token'] ?? throw new LogicException('The invitation token was not returned.');
        if ($linkOnly) {
            $this->events->dispatch(new UserInvitationLinkGenerated($parameters->login, $email));

            return $this->responses->scalar($request, $this->links->make($token));
        }

        $this->notifier->notify($parameters->login, $email, $token, $parameters->expiryDays);
        $this->events->dispatch(new UserInvitationResent($parameters->login, $email));

        return $this->responses->success($request);
    }
}
